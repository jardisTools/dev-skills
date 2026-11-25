### 6. The handler (door) and the `nodeFactory`

The generated handler is the only place that starts a run. It composes the steps of `response` and `repository` around the engine and orchestrates only: every step is a call onto a closure, the door branches on what a step hands back and has no loop, no status comparison, no `new`. No hand code is written into the door:

```php
$definition = $this->definition();                    // private method of the door: the ProcessDefinition of §3
$input = $this->payload();
$findings = ($this->handle(ValidateInput::class, validator: $validator))($input);   // only where the input carries a rule
if ($findings->failed()) { return ($this->handle(AnswerFindings::class))($this->result(), $this->transformer(), $findings); }   // 400
// one line per `requires` name: ($this->handle(RequireResource::class))($this->resource()->auth(), 'auth');
$connection = ($this->handle(ResolveConnection::class))($this->resource()->dbConnection());
$work = fn(): ProcessOutcome => ($this->handle(ProcessRunner::class))->run($definition, $input, fn(string $class): object => $this->handle($class), $this->depth());
$run = ($this->handle(RunOwnedTransaction::class))($connection, $work, $this->depth(), transactional: $definition->isTransactional());   // OwnedResult
if ($run->failure !== null) {
    $answer = ($this->handle(AnswerThrowable::class))($this->result(), $this->transformer(), $run->failure, $run->owner, $definition->getName());
    ($this->handle(LogFailure::class, logger: $this->resource()->logger()))($run->failure, $definition->getName(), $run->owner, $answer);
    return $answer;
}
return ($this->handle(AnswerOutcome::class))($this->result(), $this->transformer(), $run->result, $definition->getName());
```

The run is not a class of its own: the door builds it from `ResolveConnection`, `ProcessRunner::run(...)` and the transaction bracket `RunOwnedTransaction` (package `repository`: savepoint `sp_{depth}`, nested above depth 0, failure read from the outcome; `OwnedResult` = outcome, who owned the transaction, the failure instead of a throw). One path for every process: the definition's switch goes into the bracket as an argument (`transactional: $definition->isTransactional()`); a process with `transactional(false)` runs without begin/commit, and the package answers owner and failure in the same `OwnedResult`. The success answer is built by the engine from `answerFrom(...)` of the definition (the end of the run, `support-workflow`); the projection onto the outer wire form (date-time, rows) is not the door's business but the one outer projection at the BC process facade (`ProjectOutcome` with one `OutcomeForm` per outcome, built on `ReshapeAnswer` / `ProjectAnswerRows`, `{BC}Process::{name}()`). The door gets the depth from itself; tenant and user are no argument of the runner (they ride in the process input). `handle()` starts a closure with the payload and the version of the run only.

The complete door with every line is the Quick Start of the `response` README. `run()` takes a `\Closure(class-string): object`. The runner asks it for each node when the node is due, by the class name the definition declares — also once per pass of a loop — and checks the object is callable. The generated factory is `$this->handle($class)`, so the node is built through the kernel seam and class-version resolution of nodes is a property of that seam (`generated-code-versioning`). `$depth` is `$this->depth()`: 0 for a top-level run, the depth of the calling run plus one for a sub-process.

