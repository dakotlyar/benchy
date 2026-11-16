// Makes the signed bundles of the E4.4 C3 end-to-end test, with the engine's own build and signing code and a key that exists only in this
// process (nothing private is written). It is not part of Benchy: serve.sh copies it into a throw-away copy of the engine tree (so it can reach
// the engine's internal packages), builds it there and runs it. Usage: mkbundle <authoring/good dir> <out dir>
// Writes <out>/spki.txt (the public key, base64 SPKI), <out>/one.zip (public tier, sequence 1: benchy/hello-bench 1.0.0) and <out>/two.zip
// (sequence 2: the same test also at 1.1.0).
package main

import (
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/dakotlyar/benchy-engine/internal/catbuild"
	"github.com/dakotlyar/benchy-engine/internal/catbuild/catbuildtest"
	"github.com/dakotlyar/benchy-engine/internal/catindex"
)

func must(err error) {
	if err != nil {
		fmt.Fprintln(os.Stderr, "mkbundle:", err)
		os.Exit(1)
	}
}

func copyTree(from, to string, edit func(name, content string) string) {
	must(filepath.WalkDir(from, func(p string, d fs.DirEntry, err error) error {
		if err != nil || d.IsDir() {
			return err
		}
		b, err := os.ReadFile(p)
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(from, p)
		content := string(b)
		if edit != nil {
			content = edit(rel, content)
		}
		dst := filepath.Join(to, rel)
		if err := os.MkdirAll(filepath.Dir(dst), 0o755); err != nil {
			return err
		}
		return os.WriteFile(dst, []byte(content), 0o644)
	}))
}

func main() {
	good, out := os.Args[1], os.Args[2]
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	must(err)
	spki, err := catindex.SPKI(&key.PublicKey)
	must(err)
	trust, err := catindex.NewTrust([]catindex.KeySpec{{Generation: 1, SPKI: spki}}, nil, nil)
	must(err)
	must(os.MkdirAll(out, 0o755))
	must(os.WriteFile(filepath.Join(out, "spki.txt"), []byte(spki), 0o644))
	pol := catbuild.Policy{FreeLicenses: []string{"GPL-3.0-or-later", "MIT"}}
	issued := time.Date(2026, 11, 20, 15, 0, 0, 0, time.UTC)
	work, err := os.MkdirTemp("", "mkbundle")
	must(err)
	defer os.RemoveAll(work)
	var prev *catindex.State
	for i, step := range []struct {
		name     string
		versions []string
	}{{"one", []string{"1.0.0"}}, {"two", []string{"1.0.0", "1.1.0"}}} {
		src := filepath.Join(work, fmt.Sprintf("src%d", i))
		for _, v := range step.versions {
			copyTree(good, filepath.Join(src, "tests", "hello-"+v), func(name, c string) string {
				if name == "benchy-test.toml" {
					c = strings.Replace(c, `version = "1.0.0"`, `version = "`+v+`"`, 1)
				}
				return c
			})
		}
		built, err := catbuild.Build(catbuild.BuildOptions{FirstRelease: prev == nil, Previous: prev, Src: src, Out: filepath.Join(work, fmt.Sprintf("stage%d", i)), Policy: pol, Issued: issued})
		must(err)
		b := built["public"]
		if b == nil {
			must(fmt.Errorf("no public tier built"))
		}
		_, err = catbuild.Verify(b.Dir, pol)
		must(err)
		s, err := catbuildtest.Sign(catbuildtest.SignOptions{Dir: b.Dir, Out: filepath.Join(work, fmt.Sprintf("rel%d", i)), Key: key, Trust: trust, Previous: prev})
		must(err)
		raw, err := os.ReadFile(s.Bundle)
		must(err)
		must(os.WriteFile(filepath.Join(out, step.name+".zip"), raw, 0o644))
		lb, err := os.ReadFile(s.Ledger)
		must(err)
		prev, err = catindex.ParseState(lb)
		must(err)
	}
	fmt.Println("ok")
}
