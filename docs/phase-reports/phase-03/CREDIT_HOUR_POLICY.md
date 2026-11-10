# Credit-Hour Policy

## Model

`academic_credit_policies` stores a tenant/institution-scoped policy key and version, whether credits are enabled, theory/laboratory separation, permitted increment, maximum credits, optional contact-hour conversion factors, definition, effective dates, approval actor/time, and draft/active state. New versions are appended by key; only an explicitly activated policy is selectable for a credit-bearing course version.

Program requirements and course versions use decimal columns. Application validation constrains precision to three decimal places and uses integer milli-credit arithmetic for totals, increments, and policy caps. Credit-bearing programs need a policy and required-credit total. Non-credit course versions must have zero credits and no policy. Policies with credits disabled cannot also specify credit limits or split lab/theory credits.

## Historical meaning

Course versions retain the policy version ID used at creation/publication. Updating a policy creates a new version; it does not silently rewrite existing course versions. Row locks and transactions protect policy/course version number allocation and state changes.

## Limits

The product does not assert a universal credit definition. Institution operators must enter an appropriate documented definition and conversion basis. No GPA/CGPA, transcript, financial pricing, tuition, or fee calculation is included. Jurisdictional recognition is not inferred from a saved policy.
