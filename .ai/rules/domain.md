---
paths:
  - 'app/Domain/**'
---

# Domain

## Domain-driven structure
App code for this project's domain lives under app/Domain/<Area>/ (Models/, Support/, Actions/, ValueObjects/) rather than the default app/Models + app/Http layout - organizing by domain rather than by framework role. Before inventing a new pattern or class name in this tree, check for an existing equivalent elsewhere in the domain first.

## Interfaces only for 2+ real implementations
Don't add an interface/contract for a class with exactly one implementation - that's premature abstraction. Introduce one only when 2+ swappable strategies genuinely exist, and name it as a verb-phrase capability with no Contract/Interface suffix. A single-implementation parsing/validation class instead uses Noun+Parser or Noun+Validator naming - not a verb-phrase name.

## Domain exceptions: no suffix, one static constructor per reason
Domain exceptions drop the "Exception" suffix (e.g. CouldNotParseProfileResponse, CouldNotFetchProfile) and extend the base Exception class. Give each distinct failure reason its own named static constructor with a baked-in message (e.g. ::unparseableBody(), ::invalidLikes($value)) instead of one constructor taking a free-text message string.
