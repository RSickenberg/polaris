# Contributing to polaris

Thanks for your interest. A few rules keep the project consistent.

## Language

Code, comments, commit messages, issues and documentation are written in English.

## Commits

Commits and pull request titles follow [Conventional Commits](https://www.conventionalcommits.org/):
`feat: ...`, `fix: ...`, `docs: ...`, `chore: ...`, `refactor: ...`, `test: ...`.
Releases, version numbers and the changelog are generated from them with release-it.

## Dates

All dates are stored, computed and exchanged in UTC.

## Workflow

Work is tracked on the Polaris GitHub Project (Kanban). Pick an issue in **Ready**, open a pull request that references it, and keep pull requests small.

## Branches

- `main` is what is released: every release tag, and the `latest` image, comes from it.
- `dev` is the next release: pull requests target `dev` and are squash-merged into it. Every night a new commit on `dev` is built into the `latest-nightly` image (a beta, see the README).
- A release is a pull request from `dev` to `main`, merged with a **merge commit** (not squashed, so the two branches do not drift apart), followed by the *Release* workflow on `main`.
- A fix that cannot wait for the next release goes to `main` first, then `main` is merged back into `dev`.

## Contributor License Agreement

External contributions require signing the Contributor License Agreement described in [CLA.md](CLA.md) before a pull request can be merged.
