# Project profile

Concrete facts of this project that briefs, checkers and implementers read instead of guessing. One line per fact; replace each placeholder, delete what does not apply.

## QA entry

- Full QA run: `<make target or command>`
- Tests: `<make target or command>`
- Static analysis: `<make target or command>`
- Coding standard: `<make target or command>`
- Test call for a single file or filter: `<command with placeholder for the path or filter>`
- Door tests: `<command or path of the tests that call the public doors>`
- Model check: `<command or tool that validates the model before a build>`

## Ports

- `<service>`: `<host port>` to `<container port>`
- Ports the tests bind and that a stale process could hold: `<list>`

## Build

- Build or regenerate the artefact: `<make target or command>`
- Start and stop the containers: `<make targets>`
- Rebuild after switching the commit: `<what to rebuild before any QA run>`
- Model per tier: `<standard: model name; strong: model name; per tool>`
- Backlog file: `<path of the file that holds one line per own item at close>`

## Pitfalls

- `<trap of this project>`: `<symptom and rule>`
