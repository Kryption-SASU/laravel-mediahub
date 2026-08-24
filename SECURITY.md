# Reporting a vulnerability

⚠️ **Do not open a public issue.** A vulnerability described in a public thread is exploitable
by anyone reading it, for as long as it takes to fix and deploy.

Use the repository's *Security advisories* (**Security** tab → *Report a vulnerability*), which
open a private thread between you and the maintainers.

Describe: what you obtained, how to reproduce it, and from which permissions. A report saying
"another customer's files can be read, here is the request" is handled the same day; one saying
"the scoping looks weak" requires an investigation before anyone even knows whether there is
anything there.

## What matters most here

This package is **multi-tenant**. Anything that lets one scope reach another's files is treated
as the absolute priority, ahead of every other category — including code execution, which
already presupposes access.

Next come: URLs that grant access without expiring, uploads that escape deep validation, and
deletions that carry away more than what was named.
