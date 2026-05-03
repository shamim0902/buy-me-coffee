# Audit Rules

## Accepted Intentional Behaviors

- Membership subscription activation may create and authenticate a new WordPress user for the submitted supporter email.
- Do not report that behavior as a security finding by itself. It is intentional product behavior for the supporter account flow.
- Re-open this area only if the authenticated account receives elevated capabilities, can access another user's supporter/subscription records, or bypasses payment/subscription status checks.
