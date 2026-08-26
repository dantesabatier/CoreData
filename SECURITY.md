# Security Policy

## Supported versions

Until 1.0 is tagged, only `master` receives security fixes. Once released, the
latest minor of the current major is supported.

| Version  | Supported |
|----------|-----------|
| `master` | yes       |

## Reporting a vulnerability

**Do not open a public issue for a security problem.** Report it privately, by
either route:

- **GitHub** — [Report a vulnerability](https://github.com/dantesabatier/CoreData/security/advisories/new)
  through the repository's private advisory form.
- **Email** — `dantesabatier@me.com`, with `SECURITY` in the subject.

Please include what you have: affected version or commit, the component
involved, the steps that reproduce it, and what an attacker gains. A proof of
concept helps, but do not delay a report to build one.

You can expect an acknowledgement within 5 days, an assessment with a planned
fix date within 14, and credit in the advisory unless you would rather stay
anonymous. Please give the fix a chance to ship before disclosing publicly; if
a report goes unanswered for 30 days, treat that as consent to disclose.

## Scope

Core Data turns object-graph operations into SQL and holds an application's
data, so the following are in scope:

- **SQL generation** — any input reaching the generated statement as structure
  rather than as a bound parameter: through a predicate, a sort descriptor, a
  key path, an entity or attribute name, or a `FetchRequest` built from
  untrusted values.
- **Fetch scoping** — a fetch returning rows outside the predicate it was
  given, or a relationship traversal reaching an entity the request did not
  name.
- **The row cache** — one subject's cached snapshot served to another, or a
  cache key that collides across entities or stores. This applies to the APCu,
  Redis and Memcached backends as much as to the in-memory default.
- **Migrations** — a model or mapping that causes data loss or exposes a column
  that a previous version protected.
- **Persistent history** — reaching transactions outside the requested scope,
  or a token that leaks another store's contents.

Out of scope: vulnerabilities in an application's own model or query code
rather than in the framework, findings that require an already-compromised
database or server, denial of service through sheer query volume, and reports
produced solely by a scanner with no demonstrated impact.

The sibling libraries [Foundation](https://github.com/dantesabatier/Foundation)
and [Service](https://github.com/dantesabatier/Service) have their own
repositories; report an issue in either against the one it belongs to, or here
if you are unsure which.
