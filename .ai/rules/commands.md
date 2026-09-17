---
paths:
  - 'app/Domain/*/Commands/**'
---

# Commands

## Domain-nested commands need registering in bootstrap/app.php
Laravel's default command auto-discovery only scans app/Console/Commands. A command class under app/Domain/*/Commands/ is still invocable by FQCN (e.g. Schedule::command(FooCommand::class) works fine), but is invisible to `php artisan` directly until its directory is added to ->withCommands([...]) in bootstrap/app.php. Add each new domain Commands/ directory as it's created.
