# Licenses and SBOMs

Every version this registry publishes carries whatever its `composer.json`
declared under `license`. That is enough to answer the two questions a
compliance review actually asks — *what are we shipping under?* and *what are
we shipping with no license at all?* — and enough to emit a CycloneDX bill of
materials for the registry or for one package.

## The Licenses page

**Licenses** in the sidebar. Three counts at the top, a breakdown under them,
and a table of every version underneath, grouped by license.

The count worth watching is **Declaring none**. A version whose manifest has no
`license` key is not permissively licensed — it is unlicensed, and a consuming
project that vendors it has no grant at all. Filter the table by *Declares no
license* to get the list.

Everything on the page is scoped to your own grants, exactly as the package
list is: a license report is a list of package names, and naming a package is
what a private repository does not do.

## Where the data comes from

`package_versions.license` holds the version's declared licensing as one SPDX
expression — `MIT`, or `MIT OR Apache-2.0` when the manifest lists several.
Composer's semantics for a list is a choice between them, which is exactly what
`OR` means.

It is derived from `metadata` (which already holds the whole manifest) and kept
beside it, because every question here is asked of the whole registry at once,
and answering any of them out of JSON means decoding a composer.json per
version to read one field. As a column it is a group-by over an index.

It is recomputed whenever a version's manifest changes — sync, artifact upload,
rebuild — from one place, so the three write paths cannot drift.

## The SBOM

**CycloneDX**, JSON, one component per package **version**. A registry serves
every version and a consumer's lock file may pin any of them, so an inventory
of only the latest releases would not answer "is this thing in our estate".

Three ways to get one:

- **Licenses → Export SBOM** for everything you can see.
- **A package's page → SBOM** for that package alone.
- `php artisan sbom:export` for a pipeline.

```bash
php artisan sbom:export                                  # to stdout
php artisan sbom:export --path=storage/sbom/             # named file in a directory
php artisan sbom:export --package=acme/widgets           # one package
php artisan sbom:export --package=acme/widgets --repository=internal
```

Both the panel route and the command stream: nothing assembles the document
before writing it, so the size of the registry is not a memory ceiling.

The command is **unscoped** — there is no session to narrow to, and a caller
holding a shell on the app already holds the database it reads. Every path a
user can reach is cut to that user's grants.

### What a component looks like

```json
{
  "type": "library",
  "bom-ref": "pkg:composer/acme/widgets@1.4.0?repository_url=https%3A%2F%2Fpackages.example.com",
  "group": "acme",
  "name": "widgets",
  "version": "1.4.0",
  "description": "Widgets for Acme.",
  "purl": "pkg:composer/acme/widgets@1.4.0?repository_url=https%3A%2F%2Fpackages.example.com",
  "licenses": [{ "expression": "MIT" }],
  "externalReferences": [
    {
      "type": "distribution",
      "url": "https://packages.example.com/dist/acme/widgets/a1b2c3d.zip",
      "hashes": [{ "alg": "SHA-1", "content": "…" }]
    },
    { "type": "vcs", "url": "https://github.com/acme/widgets" }
  ]
}
```

Three choices in there are worth explaining.

**The purl carries `repository_url`.** Without it, `pkg:composer/acme/widgets`
reads as a Packagist package, and every scanner that sees it will resolve it
there — where the name is either absent or, far worse, somebody else's. The
official `cyclonedx-php-composer` plugin does not emit the qualifier yet; the
Package URL specification's Composer type definition provides for it.

**The archive's hash hangs off the distribution reference, not off the
component.** It is the sha1 of the zip this registry serves, not of the package
as a thing. That is the distinction the official plugin draws too.

**Licenses are always an `expression`**, and always the array's only element.
The more informative shape — `{"license": {"id": "MIT"}}` — is not available:
`id` is a reference to CycloneDX's SPDX enumeration, a closed list, so one
mistyped license in one repository's `composer.json` would make the entire
document fail validation. `expression` is an unvalidated string, and Composer
already requires the field to be an SPDX identifier, so the string is an
expression by contract. The one exception is `proprietary` — the one value
Composer documents that SPDX does not define — which goes out as
`{"license": {"name": "proprietary"}}`.

A version declaring nothing has **no `licenses` key at all**, rather than an
empty array: an empty array reads as an assertion that the component has no
license, where the truth is that nobody said.

### Spec version

**1.6** is emitted. 1.7 is the current release (October 2025, standardised as
ECMA-424) but tooling has not caught up — Dependency-Track and Trivy both still
reject 1.7, and the official `cyclonedx-php-composer` plugin still defaults to
1.5. 1.6 is the newest version a consumer can be assumed to read.

Verified against the CycloneDX specification repository in August 2026:

| Fact | Where |
| --- | --- |
| 1.7 current, 1.6 prior stable | [cyclonedx.org/specification/overview](https://cyclonedx.org/specification/overview/), [schema directory](https://github.com/CycloneDX/specification/tree/master/schema) |
| `bomFormat` + `specVersion` the only required keys; top level closed to additions | [`bom-1.6.schema.json`](https://github.com/CycloneDX/specification/blob/master/schema/bom-1.6.schema.json) |
| `$schema` is `http://`, not `https://`, matching the schema's `$id` | same |
| `serialNumber` pattern is lowercase-hex only | same |
| `metadata.tools` as an array deprecated in 1.5, replaced by an object with `components`/`services` | [`bom-1.5.schema.json`](https://github.com/CycloneDX/specification/blob/master/schema/bom-1.5.schema.json), [1.7 fixtures](https://github.com/CycloneDX/specification/tree/master/tools/src/test/resources/1.7) |
| `hashes[].alg` enum spells it `SHA-1` | [`bom-1.6.schema.json`](https://github.com/CycloneDX/specification/blob/master/schema/bom-1.6.schema.json) |
| `license.id` is `$ref: spdx.schema.json` (a closed enum); `expression` is a plain string with no pattern | same |
| 1.6 caps the expression form of `licenses` at one item; 1.7 lifted it | same, and [1.7 JSON reference](https://cyclonedx.org/docs/1.7/json/) |
| `externalReferences[].type` admits `distribution` and `vcs` | same |
| purl form `pkg:composer/{vendor}/{name}@{version}`, lowercased, `repository_url` qualifier supported | [purl-spec composer definition](https://github.com/package-url/purl-spec/blob/main/types/composer-definition.json) |
| The official plugin's conventions (`type: library`, hashes on `externalReferences`, purl without qualifiers) | [`cyclonedx-php-composer` Builder.php](https://github.com/CycloneDX/cyclonedx-php-composer/blob/master/src/_internal/MakeBom/Builder.php) |

### Why not SPDX as well

The PHP ecosystem's own tooling is CycloneDX — there is no comparable
first-party SPDX generator for Composer, and `cyclonedx-php-composer` is what a
consuming project would generate its own BOM with, so a CycloneDX document from
this registry is directly comparable with one.

A second format maintained for nobody in particular is a second format to keep
correct. If a consumer ever needs SPDX, it should be generated from the same
rows rather than by a parallel exporter that drifts.

## What this is not

- **Not license enforcement.** Nothing here blocks a package because of what it
  declares. A registry that refused to publish on a license would be a registry
  that broke a release at the worst possible moment; the report is for people
  who can decide.
- **Not a claim about a package's dependencies.** A component here is a package
  this registry publishes. What that package requires is in its manifest and is
  the consuming project's SBOM to assemble.
- **Not a substitute for reading the license file.** `composer.json` says what
  the maintainer intended to declare. It is evidence, not the grant.
