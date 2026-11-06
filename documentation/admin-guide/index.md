# Benchy administrator's guide

This guide is for the person who installs and looks after a Benchy Phoromatic server: the machine that holds the schedules, hands out jobs to test machines and collects their results. If you only run benchmarks at a desk, you want the [user guide](../user-guide/index.md) instead. It covers **Benchy 0.9.7**.

## Chapters

1. [Set up a Phoromatic server on a new Ubuntu 26.04 machine](new-server.md): from a fresh headless server and the Debian package to a server that is reached over HTTPS, with its first account and its first paired machine. It also covers backups, upgrading and the messages you are most likely to meet.

## Where to look next

| Page | What it is for |
|---|---|
| [`https.md`](../https.md) | How the built-in HTTPS works: certificate sources, the plain-HTTP modes, how clients trust the server, and sizing the PHP workers. |
| [`deploy/deployments.md`](../../deploy/deployments.md) | How the Debian and RPM packages are built, and what they install. |
| [Pair this machine](../user-guide/pair-this-machine.md) | The test machine's half of pairing, for the people you hand pairing strings to. |
