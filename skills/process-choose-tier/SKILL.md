---
name: process-choose-tier
description: Use when a task arrives and you must decide how much process it needs — answer, single action, small assignment or undertaking — or when a chat ends with substance worth keeping; covers tier criteria, escalation, small-change hygiene, chat-end offer.
zone: process
persona: O
prerequisites: [foundation-working-principles]
next: [process-check-existing]
---

## Scope

Process is a means against size and risk, not a default. Before any work, pick the lowest tier that fits the task and name the tier in one line. This skill holds the four tiers, the escalation rule, the hygiene that applies at every tier, and the offer to make at the end of a chat.

### 1. The four tiers

| Tier | Criteria | How |
|---|---|---|
| **0 Answer** | A question, an explanation, a comment, a typo | No process. Answer directly. |
| **1 Single action** | **One** thing in **one** place, no open decision, doable in one go (a knowledge update, a doc sync, a clear bugfix) | The main dialogue does it itself, without apparatus. Hygiene of section 3. Run only the gates the change touches; text and docs trigger no full QA run. |
| **2 Small assignment** | One thing that is big, loud or needs isolation — **or several subtasks**, even when each one alone would be a single action | One sub-agent per subtask with a short brief; independent ones in parallel where the tool can, otherwise one after another with a fresh context per role. The main dialogue keeps the task list, supervises and verifies against ground truth. It does not do the work itself. Otherwise as tier 1. |
| **3 Undertaking** | An open decision, several dependent steps, new architecture, a public API or observable behaviour, data, migration or security | The full process in stages 0 to 4: concept, PRD, plan, stage runs, verification, close. Orchestrator, task list, review board, verifier, QA gates per `PROJECT_PROFILE` for every stage. Start with `process-concept`. |

### 2. Escalation

<!-- rule:tier-escalate -->
Pick the lower tier when in doubt. Apparatus (sub-agent, task list, review board, full QA run) is a means against size and risk, not a default: escalate a tier only with a named reason, and state that reason in one sentence. This holds for the apparatus, not for the hand: two or more subtasks are never a single action, they are a small assignment at least. The hygiene of section 3 applies at every tier.

Escalate from tier 1 or 2 to tier 3 as soon as one tier-3 criterion shows up during the work: stop, say which criterion, and open stage 0 instead of continuing.

Before proposing anything at tier 2 or 3, run `process-check-existing`: a proposal without the answer to "what does the target environment already do?" is a guess with a blueprint.

### 3. Small-change hygiene

Runs without concept, PRD, review board or verifier, but never without hygiene. It applies at every tier.

1. Update the documentation in the same move.
2. Add tests for changed logic.
3. Run only the gates the change touches: code goes through the code review and its scope gate, text goes through none. The concrete gates are the QA gates of `PROJECT_PROFILE`.
4. Read the diff back before reporting.
5. `feat:` and `fix:` commits carry `Wissen: <seite>#<abschnitt>` or `Wissen: keins – <Grund>`; a topic page the change contradicts is corrected in the same commit. Format and sections: `knowledge-record-decision`.
6. Commit and push only when the human says so.

### 4. Chat end

A chat that produced more than an answer ends with an offer, never with an action. The direction is fixed: chat, then project documents, then knowledge.

<!-- rule:chat-end-offer -->
When a chat ends with a decision, a plan or a topic that will be continued, ask once: "Shall I create a project folder?" The folder is `docs/vorhaben/<name>/` with the progress file inside it. If the human agrees, create it from what the chat settled. When a chat or a finished piece of work settled a lasting decision, ask once: "Shall I carry knowledge into the pool?" and record it per `knowledge-record-decision`. Ask neither when nothing lasting came out of the chat. Create nothing without a yes.

The human decides both offers. If the project keeps its documents out of the commit (switch `process-docs` set to `local`), say so in the offer: the folder stays local.

### 5. Reference

- Existing-capability check before a proposal: `process-check-existing`
- Decision and commit-note format: `knowledge-record-decision`
- Working rules for every task: `foundation-working-principles`
