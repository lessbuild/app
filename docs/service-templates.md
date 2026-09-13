# Curated service templates

BuildPusher application presets and curated service templates are related but
not identical. An application preset supplies runtime defaults for a new
project. A curated service template additionally declares the operational
information required before BuildPusher can advertise a supported topology.

## Current contract

The curated contract is defined in `config/application-templates.php` under
`service_template` and normalized by `ApplicationTemplateCatalog`. The
currently published presets are:

| Preset | Version | Runtime | Managed preview resources |
| --- | --- | --- | --- |
| `laravel` | `1.0.0` | PHP/Laravel | PostgreSQL and Valkey |
| `laravel-inertia` | `1.0.0` | PHP/Laravel + Inertia | PostgreSQL and Valkey |
| `laravel-api` | `1.0.0` | PHP/Laravel API | PostgreSQL and Valkey |
| `node` | `1.0.0` | Node.js | PostgreSQL and Valkey |

Each definition records:

- runtime/framework compatibility;
- required resources and whether credentials are generated;
- persistent data locations and retention behavior;
- web, process and resource readiness checks;
- bounded process/resource limits;
- backup and restore scope;
- upgrade guidance;
- initialization where the preset has a safe default, provisioning and cleanup
  recovery guidance; and
- deletion and retention behavior.

These fields are non-secret support metadata. They do not contain generated
credential values, copy application secrets or execute a provider operation.

## Installed version identity

When a new project uses a curated preset, `projects.template_version` records
the definition version used to create its initial environment. The field is
nullable for projects created before this contract and for presets that are not
yet curated. Existing rows are not backfilled and a configuration edit does
not silently change the recorded version.

A future template upgrade must compare the installed version with the current
definition, show the resource/process/data consequences, and use the existing
configuration review/apply workflow where it changes application state. A
template definition change alone is not an upgrade and must not mutate an
existing installation.

## Node composition

The `node` preset composes the existing runtime, managed-resource, readiness and
cleanup paths. It has no worker process or automatic initialization command:
application-specific migrations and seed data remain part of the reviewed
repository deployment. Preview builds receive the managed PostgreSQL and Valkey
variables through the same encrypted deployment snapshot used by other
supported presets. A preview inherits the selected nonpreview environment's
runtime type, version, build command, start command, port and Dockerfile path;
the default Laravel values remain unchanged.

`nextjs` remains an existing but unpublished Node runtime preset. It is not
silently upgraded to the curated contract because framework-specific build,
initialization, recovery and compatibility behavior still needs its own
evidence.

## Recovery boundaries

The published 1.0.0 contracts describe the existing local support boundaries:

- PostgreSQL application data and BuildPusher control-plane data are separate
  recovery scopes.
- Valkey is disposable preview state and is not treated as application-data
  backup evidence.
- Preview initialization is sample-data-oriented, revision-bound and
  retryable; production-data copying is not implied.
- Preview cleanup removes only exact resources marked as owned by that preview;
  incomplete cleanup remains visible and retryable.
- Readiness metadata describes checks that the application can support. It is
  not evidence that a remote provider, SSH connection, application, database,
  cache or process is currently healthy.

Installation/upgrade execution, additional service catalog entries and
provider/cloud acceptance are not covered by this contract slice. They require
their own lifecycle and recovery evidence before being expanded further.
