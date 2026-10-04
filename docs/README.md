# Strukt Ar documentation

A small query builder and active record ORM for SQL databases. It sits directly
on PDO, parameterizes every statement, and keeps the moving parts small enough
to read in one sitting.

Topics:

- [Connections](connections.md) — opening, registering, and transactions.
- [Query builder](query-builder.md) — fluent, always-parameterized statements.
- [Active record](active-record.md) — one class, one row, `save()`/`delete()`
  run at once.
- [Relationships](relationships.md) — `hasMany()`, `hasOne()`, `belongsTo()`.
- [Schema](schema.md) — building tables from model declarations.
- [Generators](generators.md) — `model:generate`, `db:create`, and the
  inspector they build on.

Managed entities — an identity map, a unit of work, and result caching over
these very same models — live in the separate `strukt/orm` package:
[Strukt Orm](../orm/docs/README.md).