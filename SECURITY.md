# Security Policy

## Status

`laravel-rls` is early-stage software. It has **not** been independently audited
or security-reviewed. Read the status banner in the [README](README.md) before
relying on it as a security boundary, and see
[When not to use it](README.md#when-not-to-use-it) for the cases where RLS is a
correctness backstop rather than a containment boundary.

## Supported versions

Until a `1.0.0` release, only the latest `0.x` tag receives fixes.

| Version | Supported |
|---------|-----------|
| `0.x` (latest) | ✅ |
| older `0.x`    | ❌ |

## Reporting a vulnerability

For anything that could let one tenant read or write another tenant's rows, or
that lets isolation be turned off out of band, please report **privately** —
do not open a public issue.

- Preferred: GitHub's [private vulnerability reporting](https://github.com/Radiergummi/laravel-rls/security/advisories/new).
- Or email **moritz.friedrich@gmail.com** with a description and, ideally, a
  minimal reproduction (a failing isolation test is perfect).

Please allow a reasonable window for a fix before any public disclosure. Where a
genuine leak is found, the project's stance is to characterize and document it
in the open once fixed, not to hide it.

## Scope

In scope: any bypass of the isolation policies this package installs, context
leaking across a request/job/transaction boundary, or the documented fail-loud
guards failing open.

Out of scope (documented boundaries, not bugs — see
[How it works](README.md#how-it-works)): `SECURITY DEFINER` functions, queries
deliberately run on the configured `admin_connection`, and running as a
superuser or `BYPASSRLS` role, all of which are outside what RLS confines.
