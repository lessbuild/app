# Buildpusher agent coordination

Use GPT-6 Sol as the primary orchestrator, GPT-6 Luna at max reasoning effort for delegated implementation, and GPT-6 Astra as an advisor. The user explicitly requests this arrangement.

- Sol owns architecture, task assignment, security decisions, integration, and deployment. Sol reviews worker output and makes the final decisions.
- Delegate bounded implementation, code investigation, documentation, and test authoring to Luna at `max` reasoning. Give each worker a clear task, relevant context, assigned files, and acceptance criteria.
- Ask Astra for read-only architecture, security, and critical-change advice at review checkpoints. Astra reports findings to Sol; it does not direct workers or edit implementation files.
- Use at most three concurrent subagents and coordinate file ownership. With an Astra advisor active, use at most two simultaneous Luna workers; rotate the advisor slot when a third worker is more useful.
- Where the runtime accepts explicit model selection, request `gpt-6-luna` with `max` reasoning for workers and `gpt-6-astra` for the advisor. Use a focused task brief when a full-history fork prevents model overrides.
- Preserve the user's instruction: author tests, but do not run the automated test suites until the full plan's source implementation is complete.
