#### Continuation §2 — steps 6 and 7: `build`, findings, reading the generated code

6. **`build`** — a long-running Tool: generates the aggregate (and/or process) code tree onto
   disk. Reports progress notifications; a concurrent build on the same scope refuses with
   `BUILD_RUNNING`, an unsaved designer draft with `DRAFT_EXISTS` (§6). `build_domain` builds a
   whole domain in one call, in build order: aggregates before the processes of the same BC and
   producer BCs before their consumers (edges from `consumedCalls`; ties keep the alphabetical scan
   order; on a mutual-call cycle the order breaks at its first member without an error and the
   second side stays `not-checkable`). A consumer built before its producer would report
   `not-checkable` for the cross-BC call, so do not work around it with a hand-ordered BC loop.
   `build_domain` does not stop at a blocked element: it builds every element whose gates pass
   and lists each blocked one in `failed[]` with `status`, `code`, `message` and, for a rule
   refusal, `details`; `ok` is `false` when `failed[]` is not empty.
   **The answer of `build_domain` is a head of fixed size plus a findings file.** The head holds
   numbers (`elementsCount`, `failedTotal`, `filesBackedUpCount`, `warningsCount`,
   `suggestionsCount`, `findingsCount`, `findingsByRule` per rule ID, `overriddenCount`,
   `contextMapDeviationsCount`), the file counters, `logPath` / `logResourceUri` and
   `findingsPath` / `findingsResourceUri`, and `failed` as the first ten blocked elements only
   (`kind`, `bc`, `element`, `blockers`, `rule`, `hint` cut to 200 characters). The complete lists
   (`elements`, `failed` with `details`, `filesBackedUp`, `warnings`, `suggestions`, `findings`,
   `overridden`, `contextMapDeviations`) stand in the findings file next to the log; read it
   through `findingsResourceUri` (`jardis://mcp-log/{name}`). A `findingsFileError` in the head
   means the build is valid but the file could not be written. `build` and `build_process` keep
   their lists in the answer.
   **Findings of a run (one form at every door).** `build`, `build_process`, `build_domain`,
   `validate_process` and `save_process` report a finding as
   `{ruleId, severity, process, nodeId, file, line?, subject, message, hint}`;
   `validate_definitions_all` keeps its own rows (`severity` `error`·`warning`·…, `rule`,
   `message`) and adds `ruleId`, `v1Severity` and `subject` beside them. In that form `severity`
   is `blocker`, `warning` or `suggestion`, `subject` has exactly one of the forms
   `{fields: [...]}`, `{field}`, `{query}`. A refusal of the model check, of the handle
   consistency, of the answer references or of the final bodies (undeclared reject, call,
   construct) carries every blocker of the run in `details.findings[]`; a refusal of the door,
   rule, query, event-node or value-list gates carries one blocker in `details.findings[]` and
   names all blockers of the first failing gate in `message`. A blocker of the model answers 422
   `VALIDATION`, a blocker of the generator's own self-check (`GATE-HANDLE-CONSISTENCY`) 500
   `INTERNAL`. `ruleId` and `hint` of the envelope are those of the first finding; `location` is
   empty at a gate refusal, and a first finding without a hint leaves the general hint of the
   error code. The gates before the first write (model, handle consistency, answer reference) and
   the gates on the final bodies (undeclared reject, call, construct) each collect every blocker
   of all requests (a request with a model blocker is not checked for handle consistency and
   answer reference), and a blocker writes nothing. A run that goes through
   returns the warnings and suggestions whose rule is known as `findings` (always an array; the
   array of `build_domain` stands in its findings file and adds the `scope` of the element); the
   text lists `warnings` / `suggestions` and the progress events stay as they were. A standing note
   without need to act (`V-XBC-404-BOUNDARY`, `V-SUBPROC-400-BOUNDARY`: what a foreign BC or a
   sub-process answers reaches the caller only as far as the hand body passes it on) is ONE line
   per process with the number of its nodes, one line per domain list over several processes;
   the full wording stands in the paragraph "Grenze (kein Defekt)" of the PHPDoc of the generated
   class. A domain run reports it only as a finding, never in the `suggestions` text. `validate_process` and `save_process` return `findings`
   next to the old buckets `errors` / `warnings` / `suggestions`. The hints of `V-PROC-DUPCHECK`
   and `V-PROC-READ-UNDECLARED` name the skill `generated-code-request-path`.
7. **Read the generated code** — via the `code-tree` and `code-file` Resource templates
   (chunked reads with an offset/limit window; large files stay well inside the response cap).
   This replaces "open it in the editor" from the browser flow.
