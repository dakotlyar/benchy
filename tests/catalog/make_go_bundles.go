package main

import (
	"archive/zip"
	"bytes"
	"compress/flate"
	"io"
	"os"
	"time"
)

// Builds bundles the way benchy-engine's catbuild.writeBundle does (zip.Writer.CreateHeader, deflate or store, one fixed stamp),
// around the engine's public fixture index. No key is involved.
func main() {
	idx, _ := os.ReadFile(os.Args[1])
	out := os.Args[2]
	stamp := time.Date(2000, 1, 1, 0, 0, 0, 0, time.UTC)
	mk := func(name string, method uint16, level int) {
		var buf bytes.Buffer
		zw := zip.NewWriter(&buf)
		zw.RegisterCompressor(zip.Deflate, func(w io.Writer) (io.WriteCloser, error) { return flate.NewWriter(w, level) })
		h := &zip.FileHeader{Name: "index.signed", Method: method, Modified: stamp}
		h.SetMode(0o644)
		w, _ := zw.CreateHeader(h)
		w.Write(idx)
		zw.Close()
		os.WriteFile(out+"/"+name+".zip", buf.Bytes(), 0o644)
	}
	mk("go-writer-deflate", zip.Deflate, flate.BestCompression)
	mk("go-writer-store", zip.Store, 0)
}
