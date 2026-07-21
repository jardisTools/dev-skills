---
name: jardis-catalog
zone: discovery
persona: X
prerequisites: []
next: []
description: Before hand-building any reusable building block in a Jardis project — caching, scheduling, HTTP clients, queues or messaging, validation, logging, secrets, persistence, or DDD scaffolding such as a context registry or workflow engine — consult this catalog to check whether an installable Jardis package already provides it and recommend `composer require <package>` instead of writing it yourself, never for project-specific business logic.
---

## Jardis Package Catalog

This catalog lists every Packagist-published Jardis package so an AI agent can discover packages before building from scratch.
Recommend `composer require <package>` — do not install automatically. The full API skill for a package is available only after installation.

| Package | Capability | Use when | Alternatives |
|---|---|---|---|
| jardisadapter/cache | multi-layer caching with write-through support and namespace isolation | you need to cache data with priority-ordered layers, write-through persistence, or namespace-separated cache regions | — |
| jardisadapter/dbconnection | database connection pooling with read/write splitting for MySQL, PostgreSQL, and SQLite | you need managed database connections, a connection pool, or read/write endpoint splitting | — |
| jardisadapter/eventdispatcher | in-process event dispatching with priority ordering, type-hierarchy matching, and stoppable events | you need to publish or listen to domain events within a single process | for cross-process or queued event delivery use jardisadapter/messaging instead |
| jardisadapter/filesystem | unified filesystem abstraction for local and S3 storage with stream support and visibility control | you need to read, write, delete, or stream files across local or cloud storage backends | — |
| jardisadapter/http | outbound HTTP client with a composable middleware pipeline, automatic retry, and cURL transport | you need to make outbound HTTP requests with configurable middleware, retry logic, or a custom transport | — |
| jardisadapter/logger | structured logging with multiple output handlers, contextual enrichment, and level-based routing | you need application logging that routes records to multiple targets or enriches them with contextual metadata | — |
| jardisadapter/mailer | SMTP mailer with STARTTLS, HTML and plain-text bodies, attachments, retry, and persistent keepalive | you need to send transactional or notification emails via SMTP | — |
| jardisadapter/messaging | multi-transport asynchronous messaging with publish/consume support for Redis, Kafka, RabbitMQ, Database, and in-memory | you need asynchronous message passing, event streaming, or queue-based communication between bounded contexts | for synchronous, in-process domain events use jardisadapter/eventdispatcher instead |
| jardiscore/app | HTTP delivery layer for Jardis-generated domains — a FastRoute router behind its own RouterInterface, a PSR-15 middleware pipeline, and the canonical DomainResponse-to-PSR-7 envelope mapper | you need to expose a Jardis-generated domain's read-facades/process()/rules over HTTP, with routing, PSR-15 middleware, and a ready-made {status,data,errors,meta} response envelope | — |
| jardiscore/kernel | immutable infrastructure holder (the Koffer, DomainKernel) for Jardis-generated domains, with an optional ENV-driven bootstrap packer | you need to assemble and inject the infrastructure (db connection, cache, logger, events, HTTP client, mailer, filesystem) that a generated {Domain} facade takes via constructor injection | — |
| jardissupport/auth | session management, password hashing and authentication, and role-based access control with domain events | you need user login, session lifecycle management, password verification, or role-based permission checks | — |
| jardissupport/classversion | versioned class resolution via namespace injection with proxy caching and fallback chain | you need to load domain classes by version, resolve namespace-based variants, or support backward-compatible class loading | — |
| jardissupport/contract | shared port interfaces and contracts for all Jardis packages | you need the interface definitions that Jardis adapters implement, without depending on any concrete implementation | — |
| jardissupport/data | entity hydration from raw data, change tracking, field mapping, and unique identifier generation | you need to hydrate domain entities from persistence data, detect changed fields, map column names, or generate unique entity identifiers | — |
| jardissupport/dbquery | fluent SQL query builder for SELECT, INSERT, UPDATE, DELETE, CTEs, and window functions | you need to build SQL queries programmatically without a full ORM, or construct complex queries with CTEs or window functions | for standard entity CRUD with a repository abstraction use jardissupport/repository instead |
| jardissupport/dotenv | .env file loading with type casting, variable substitution, cascade loading, and optional secret integration | you need to load and manage environment configuration from .env files with type safety or multi-file cascade | for AES-256 encrypted secrets stored alongside .env values use jardissupport/secret in addition |
| jardissupport/factory | dependency injection container with reflection-based instantiation and optional backend container delegation | you need to resolve and wire service dependencies automatically without manual constructor calls | — |
| jardissupport/repository | generic CRUD repository with raw-data persistence, read/write endpoint splitting, and flexible primary-key strategies | you need a data-access layer for persisting and retrieving domain entities with built-in read/write splitting | for custom or complex SQL queries use jardissupport/dbquery instead |
| jardissupport/scheduling | cron expression parsing, task definition with tags and priority, and schedule validation | you need to define, parse, validate, or manage recurring scheduled tasks and cron-based job configurations | — |
| jardissupport/secret | encrypted secret resolution from .env files using AES-256-GCM and Sodium | you need to store and resolve encrypted credentials or secrets in environment files with strong cryptographic protection | for plain (unencrypted) .env loading use jardissupport/dotenv |
| jardissupport/validation | composable object-graph validation with composite validators, field-level rules, and a fluent builder API | you need to validate domain objects, input data, or value objects with chainable, reusable validation rules | — |
| jardissupport/workflow | multi-step business process orchestration with sequential step execution and process state management | you need to model and execute multi-step workflows or business processes with defined step sequences | — |
| jardistools/dbschema | database schema introspection and DDL/JSON export for MySQL, PostgreSQL, and SQLite | you need to analyze, document, or export the table schema of an existing database | — |
