---
paths:
  - 'app/Domain/*/ValueObjects/**'
---

# Value Objects

## DTOs live in ValueObjects/, not Support/
Value objects/DTOs get their own ValueObjects/ subfolder per domain area. Plain classes (not readonly by default), promoted constructor properties, named static factory methods for special/failure states (e.g. UpstreamResponse::failed()) rather than nullable-everything constructors.

## Self-parsing DTOs are fine for small external-response validation
Don't default to a separate NounParser class for every case. When a DTO's whole job is "turn one external response into validated data or a specific typed failure," a self-parsing static factory on the DTO itself (paired with a matching exception's one-static-constructor-per-reason - see ParsedProfileResponse::fromUpstreamResponse() and CouldNotParseProfileResponse) is a reasonable shape as long as the validation is small and won't be reused outside producing that one DTO. Reach for a separate parser class only when the validation is substantial or genuinely reused elsewhere.
