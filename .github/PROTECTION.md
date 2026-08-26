# Branch protection

The rule that guards `main` is written in [`ruleset-main.json`](ruleset-main.json) rather than
left in a settings page, so that it can be read, reviewed and restored like anything else here.

Apply it to a repository:

```bash
gh api --method POST repos/OWNER/REPO/rulesets \
    --input .github/ruleset-main.json
```

To update an existing rule, list the rulesets to find its id and use `PUT
repos/OWNER/REPO/rulesets/ID` with the same file.

⚠️ **THIS FILE IS A DESCRIPTION, NOT THE STATE.** Reading it says what the rule should be, never
what is in force. The rule can also live at the **organisation** level, where it targets
repositories by name and does not appear among the repository's own rulesets — a repository can
therefore be fully protected while `repos/OWNER/REPO/rulesets` looks empty. What is actually
enforced on a branch is only ever answered by asking:

```bash
gh api repos/OWNER/REPO/rules/branches/main
```

This is not hypothetical: a `required_status_checks` rule described here was, for a time, absent
from the ruleset in force. The pipeline was green and gated nothing.

## What it enforces

- **`main` cannot be deleted**, and cannot be force-pushed.
- **Every change arrives through a pull request.** No direct commits, including from the
  maintainer.
- **All checks must be green** before the merge button unlocks — the twelve listed in the file,
  the contributor agreement among them. ⚠️ A check that never reports on some pull requests
  would block them for ever, so a context is only listed here once it has been seen reporting on
  a documentation-only change as well as on a code one.
- **Conversations must be resolved** before merging, so a review comment cannot be merged past
  by accident.

## What is deliberately NOT enforced

**No required approvals.** ⚠️ GitHub does not let anyone approve their own pull request, so on a
project with a single maintainer, requiring one approval means the maintainer can never merge
their own work. The count is therefore zero, and the protection comes from elsewhere — see
below.

**No restriction on who may merge.** ⚠️ It would be redundant. On a public repository, an
outside contributor has no write access at all: they can open a pull request and nothing more.
The merge button only exists for people with write access, which is the maintainers. Adding a
rule to say so would only be one more thing to keep in step.

**Branches do not have to be up to date before merging.** Requiring it makes every contributor
rebase each time anything else lands, and re-runs the whole pipeline for changes that touch
nothing in common. The cost is real and the benefit is a rare semantic conflict, which the
suite catches on `main` anyway.

## The branch that must stay unprotected

⚠️ **`cla-signatures` IS NOT PROTECTED, AND THAT IS DELIBERATE.** The contributor agreement
automation records a signature by committing a file. It cannot open a pull request, and it must
not be given a way around the rule on `main` — so the signatures live on a branch of their own,
which carries nothing else.

Pointing that automation at `main` fails with *Repository rule violations found*. The job goes
red, the signature is never recorded, and the first outside contribution can never be merged:
the gate looks present and holds nothing. Protecting `cla-signatures` reopens the same hole,
just as quietly.

### Create its signature file at the same time

⚠️ **The agreement check fails on its very first run if the file is missing** — the action
creates it and reports a failure in the same pass. Measured here: the first pull request opened
against a fresh repository showed a red check for a signature nobody owed, and a re-run with no
change turned it green.

That is a bad first impression on a repository whose whole point is to accept contributions, and
it is avoidable. When the branch is created, put an empty catalogue on it:

```
.github/cla/signatures.json     {"signedContributors": []}
```

The failure only ever happens once per repository, which is exactly why it is easy to meet again
and hard to remember: it reappears on every recreation, and never in between.


## What GitHub cannot express

A rule of the form "`main` only accepts pull requests coming from branch X" **does not exist**.
Branch protection can require a pull request, reviews and checks; it cannot constrain the
source branch. Anything of that shape has to be a check in the pipeline instead.
