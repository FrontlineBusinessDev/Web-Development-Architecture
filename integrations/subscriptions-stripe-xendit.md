# Monthly and yearly subscriptions with Stripe and Xendit

A reusable guide for adding paid plans, billed monthly or yearly, to a web app: **Stripe** for clients outside the Philippines, **Xendit** for Philippine clients (linked GCash, Maya, ShopeePay or card, charged automatically).

- **Applies to:** every FBS app that sells monthly or yearly plans.
- **Source:** FBS Work (`laravel-fbs-work`): `docs/subscriptions-stripe-xendit.md`, `docs/xendit-handoff.md` and the code listed in section 16.
- **Last updated:** 2026-11-12 (copied from FBS Work)

It was written from the FBS Work implementation (Laravel 12, Inertia, React) and every claim is marked as one of:

- **Verified**: seen working against the provider's sandbox or live API while building this.
- **Docs**: taken from the provider's documentation, not yet seen with a real delivery. Confirm it in your own sandbox first.

Read sections 1 to 4 for the ideas (any stack). Sections 5 to 9 are the step-by-step flows. Sections 10 and 11 are provider reference. The rest is lessons, tests and a go-live checklist. Code is Laravel, but each snippet is short enough to port.

---

## 1. What you are building

| Need | Decision |
|---|---|
| Who pays through whom | A client's **billing country** decides: `PH` pays through Xendit, everyone else through Stripe. |
| Who renews | The **provider** renews (Stripe subscription, Xendit recurring plan). Your app never runs a charge loop. |
| What your app owns | The plan, the interval, the period end, the cancellation flag, invoices, refunds, and what the client is allowed to do. |
| How the app learns what happened | **Webhooks**, plus a **return sync** (when the client lands back on your billing page) so it also works where webhooks cannot reach you (local development). |

Why two providers: a Philippine business wants to pay with GCash or Maya, which Stripe does not offer for recurring charges. HitPay was tried first and ruled out: its Philippine accounts only auto-renew with ShopeePay (no cards, GCash or Maya). Check a provider's *recurring* support per payment method and per country before building on it; one-time support says nothing about renewals.

## 2. Vocabulary used below

- **Account**: the paying customer (owns the plan, the provider ids and the period end). A workspace or team belongs to it. In this project plan state lives on the account; the workspace reads and writes it as if it were its own.
- **Plan**: `starter`, `pro`, `business` (paid) and `free` / `trial` (unpaid).
- **Interval**: `monthly` or `annual`.
- **Period end** (`current_period_end`): when the paid period ends and the next charge is due.
- **Pending**: a plan the client chose at checkout that is waiting for its first payment (`"pro|annual"`).
- **Provider id**: the id of the thing the provider renews (`stripe_subscription_id`, `xendit_plan_id`).

## 3. Pricing rules (decide these first, write them down)

1. **One fixed price per plan for the whole team**, not per user. Seats are limits, not billing units.
2. **Annual = the discounted monthly price × 12, charged once a year.** Store prices *per month* for both intervals and multiply by 12 at charge time.
3. **VAT** is added on top for the clients who owe it (here: Philippines, 12%). Prices on the page say "+ 12% VAT". Decide whether *your* fee on the provider is VAT-treated separately.
4. **Add-ons** (extra storage, projects, workspaces) are priced per month and cost the same on annual plans (the annual discount covers the plan only). On an annual plan an add-on is charged ×12.
5. **MRR** (monthly recurring revenue, for the dashboard) = plan price + add-on packs, before VAT, annual plans counted per month. Count it once per account, not once per workspace. A plan that is not paid has no add-ons.
6. **Money is stored as integers** (centavos, `*_cents`). Stripe also takes integers (smallest unit). **Xendit takes decimal pesos** (`1394.40`). Convert at the edge, in one place, and never mix.

Amount helper (the same one is used for checkout, plan edits, "next invoice" and the staff page, so they can never disagree):

```php
public static function amount(Workspace $w, string $plan, string $interval): float
{
    $months = $interval === 'annual' ? 12 : 1;
    $net = $w->planPriceCents($plan, $interval) * $months;
    foreach (array_keys(Workspace::ADDONS) as $key) {
        $net += (int) $w->{'addon_'.$key} * Workspace::addonPriceCents($key, $interval) * $months;
    }
    return round(($net + $w->vatCents($net)) / 100, 2);   // pesos, VAT included
}
```

Worked example: Starter annual (₱995 × 12 = ₱11,940) + one storage pack (₱120 × 12 = ₱1,440) = ₱13,380; + 12% VAT (₱1,605.60) = **₱14,985.60**.

## 4. Change rules: the usual SaaS way (upgrade now and prorated, downgrade at renewal)

For every change to a paying client:

| Change | When it starts | What the client pays |
|---|---|---|
| **Upgrade** (higher price, same interval) | now | the **prorated difference for the rest of the period, at once** |
| **Add-on pack bought** | now | the pack's price for the rest of the period, prorated, at once; then in every renewal |
| **Downgrade** (lower price, same interval) | **next renewal** (the client keeps the current plan until then) | nothing now; the renewal is charged at the new price; nothing is refunded |
| **Monthly ↔ annual** | **next renewal** | nothing now; the renewal is charged at the new interval's price |
| **Add-on pack removed** | now | nothing; the price drops from the next renewal; the paid part of the period is not credited |

Proration share = time left to `current_period_end` over the length of the interval that ends there. Charge the difference of the two **VAT-included** amounts times that share.

- **Stripe** does this natively: `proration_behavior=create_prorations` puts the difference (or credit) on the next invoice, and it applies a downgrade at once. Keep that, and say so in the UI text (it differs by provider: pass a `provider` prop to the page).
- **Xendit** has no proration: a recurring plan only charges its fixed amount on its schedule. So the app takes the upgrade difference as a **one-off payment on the linked payment method** (11, `payment_requests`) and keeps a **scheduled change** (`pending_plan` / `pending_interval`) for downgrades and interval switches.
- **Charge first, then change.** If the one-off payment is not confirmed, change nothing and tell the client. If the payment went through but the provider call that updates the renewal amount then fails, keep the access (it is paid for) and alert staff to fix the amount by hand.
- A scheduled change can be dropped by choosing the current plan again; cancelling drops it too.

---

## 5. Data model

Put these on the account (or customer) row. Names are examples.

| Column | Meaning |
|---|---|
| `plan`, `billing_interval` | current plan and `monthly` / `annual` |
| `billing_country` | `PH` or `ZZ` (anywhere else). Set once, at the first checkout. Drives the provider and VAT. |
| `current_period_end` | next renewal date (updated on every successful charge) |
| `cancel_at_period_end` | the client cancelled; access runs until `current_period_end` |
| `cancelled_at` | the plan has actually ended |
| `payment_failed_at`, `dunning_lock`, `read_only_at`, `suspended_at` | the failed-payment steps |
| `addon_storage`, `addon_projects`, `addon_workspaces` | packs bought (integers) |
| `stripe_customer_id`, `stripe_subscription_id` | Stripe ids |
| `pending_plan`, `pending_interval` | a downgrade or interval switch waiting for the next renewal (null when none) |
| `xendit_customer_id`, `xendit_plan_id`, `xendit_pending`, `xendit_replacing_plan_id` | Xendit ids; the plan waiting for its first payment (`"plan\|interval"`); a replacement plan while the client changes payment method |

Other tables:

- **invoices**: `workspace_id`, `amount_cents`, `refunded_cents`, `status` (`open|paid|past_due|void|refunded|...`), `paid_at`, plus the provider key: `stripe_invoice_id` (unique) or `xendit_cycle_id` (unique) and `xendit_payment_request_id` (needed to refund). A one-off prorated charge is an invoice too, keyed `prorate-<payment request id>` in `xendit_cycle_id`, so it cannot collide with a plan cycle and refunds the same way. **The unique provider key is what makes webhooks and the return sync safe to run twice.**
- **refunds**: `invoice_id`, `amount_cents`, `status` (`pending|approved|rejected|failed`), `kind` (`refund|credit`), `stripe_refund_id` / `xendit_refund_id`, `failure_reason`.
- **webhook_events**: `provider`, `event_id` (unique), `type`, `status` (`received|processed|failed|rejected`), `note`. The audit trail and the retry gate.
- **plan_changes**: `from_plan`, `to_plan`, `mrr_before_cents`, `mrr_after_cents`. Feeds the dashboard's new / expansion / contraction / churn. **Write one for every plan change *and* every add-on change**, for every provider, or the dashboard drifts from reality.

## 6. Provider interface (what each provider must give you)

Whatever the stack, give each provider one class with the same verbs. A new provider is then "implement these".

| Verb | Stripe | Xendit |
|---|---|---|
| `provider(account)` | country is not PH | country is PH and the key is set |
| `checkout(account, plan, interval)` → URL | Checkout Session (`mode=subscription`) | create customer + recurring plan, return its hosted linking page (`AUTH` action) |
| `activate` / `sync` (client returned) | webhook is the main route; the page shows "updates in a moment" if it is late | `GET recurring/plans/{id}` is `ACTIVE` → switch the plan on, then read paid charges |
| `applyEvent(payload)` | invoice / subscription / checkout / charge / dispute events | `recurring.*` and `refund.*` events |
| `changePlan(plan, interval)` | new price on item 0 of the subscription, prorated | upgrade: one-off prorated payment, then `PATCH` the plan amount; downgrade or other interval: store `pending_*`, `PATCH` the amount (same interval) or open a new plan on the same payment method and close the old one (other interval) |
| `addAddon` / `removeAddon` | add / delete a subscription item (prorated) | add: one-off prorated payment, then `PATCH` the plan amount; remove: `PATCH` the amount down |
| `cancel` / `resume` | portal (`cancel_at_period_end`) | deactivate the plan now + set the flag; resume opens a new plan on the same method |
| `changeMethod` | Stripe customer portal | replacement plan with no method yet; client links on Xendit's page; takes over when active |
| `refund(invoice, amount)` | `POST refunds` (`payment_intent`) | `POST refunds` (`payment_request_id`) |
| `nextInvoice` | `invoices/create_preview` | computed: the fixed amount at `current_period_end` |

Keep **shared steps provider-neutral** and call them from both:

```php
Billing::startPlan($ws, $plan, $interval);   // plan + interval on, period end one interval ahead, clear cancel flags, lift failed-payment lock, record PlanChange
Billing::paymentFailed($ws);                  // payment_failed_at once, tell the admins once
```

## 7. The flows

### 7.1 First subscription (checkout)
1. Client picks a plan and an interval on the billing page. Show the price with "+ VAT" when the country is PH.
2. **Billing country**: detect it from the IP (see 7.10); show it with a "Change" link; save it once. Stripe-only apps can skip this.
3. Server: refuse if already subscribed; **close any earlier unpaid attempt** (Xendit: deactivate the old pending plan, so only one is waiting); create the provider object; store `*_id` and `*_pending = "plan|interval"`; redirect (`Inertia::location`) to the provider's page.
4. Client pays or links a method on the provider's page, and is sent back to `/billing?checkout=success`.
5. **Switch the plan on** in two ways, whichever comes first: the webhook, or the return sync. Both call the same `activate` and are idempotent.
6. Show the **congratulations modal** (see 7.12).

Xendit checkout (Verified):

```php
Xendit::call('post', 'recurring/plans', [
    'reference_id' => "ws{$id}-{$plan}-{$interval}-".Str::lower(Str::random(6)),
    'customer_id' => $customerId, 'recurring_action' => 'PAYMENT', 'currency' => 'PHP',
    'amount' => $amount,                          // decimal pesos, VAT included
    'immediate_action_type' => 'FULL_AMOUNT',     // first charge the moment the client links a method
    'schedule' => [
        'reference_id' => 'sch-...', 'interval' => 'MONTH', 'interval_count' => $annual ? 12 : 1,
        'anchor_date' => now()->add($annual ? '1 year' : '1 month')->utc()->format('Y-m-d\TH:i:s\Z'),  // the SECOND charge
        'retry_interval' => 'DAY', 'retry_interval_count' => 3, 'total_retry' => 2, 'failed_attempt_notifications' => [1, 2],
    ],
    'notification_config' => ['recurring_created' => ['EMAIL'], 'recurring_succeeded' => ['EMAIL'], 'recurring_failed' => ['EMAIL']],
    'failed_cycle_action' => 'STOP',
    'success_return_url' => url('/billing?checkout=success'), 'failure_return_url' => url('/billing'),
    'metadata' => ['workspace_id' => (string) $id, 'plan' => $plan, 'interval' => $interval],
]);
// 202, status REQUIRES_ACTION, actions[0] = {action: AUTH, url: ...}  -> send the client there
```

### 7.2 Renewals
The provider charges; you only listen.
- **Success**: record one paid invoice (keyed by the provider's charge id), **start a scheduled downgrade or interval switch** (`applyPending`: plan and interval become the pending ones, and the period then runs one interval of the *new* interval), advance `current_period_end`, notify the admins.
- **Failure**: start the failed-payment steps (7.3).

Only advance `current_period_end` once per charge: skip it when the invoice for that charge id already exists (a retried webhook must not move the date twice).

### 7.3 Failed payments (the ladder)
- Provider retries on its own schedule (Stripe "smart retries"; Xendit retry settings above).
- First failure: set `payment_failed_at`, tell the admins **once**.
- A daily job: failed for 7 days → read-only; 14 days → suspended; a paid invoice lifts the lock. Staff-set locks are never claimed by the job.
- Xendit with `failed_cycle_action = STOP`: when retries run out the plan goes inactive (`recurring.plan.inactivated`) and the account ends (7.8).

### 7.4 Changing plan or interval
Follow section 4. Validate first (more users than the lower plan includes → refuse), then for Xendit: the one-off payment (upgrades only), then the provider call that sets what the **renewal** charges, then local state (`plan` now for an upgrade, `pending_*` for a scheduled change) and a `PlanChange` row. The renewal's plan and interval are `pending ?? current`; use that one helper for the plan amount, the payment method change and "next invoice".

- **Stripe**: create the new price (`price_data`) and update item 0 of the subscription with `proration_behavior=create_prorations`, quantity 1.
- **Xendit, upgrade**: `POST payment_requests` for the prorated amount on the linked method (11), then `PATCH recurring/plans/{id}` with the new `amount`, `description`, `metadata`.
- **Xendit, downgrade**: no payment. `PATCH` the plan amount to the lower price (so the renewal charges it), keep the current plan, set `pending_plan` / `pending_interval`. The renewal's succeeded cycle starts it (7.2).
- **Xendit, monthly ↔ annual** (a scheduled change like a downgrade; the scheduled interval is the Xendit plan's own interval, which can differ from the current one): a schedule cannot be edited, so open a **new plan on the payment method already linked** (read `payment_methods[0].payment_method_id` from the old plan; no `immediate_action_type`; `anchor_date` = the renewal date), then close the old one. A plan on a linked method is `ACTIVE` at once with no charge until its anchor (Verified). **Point your record at the new plan first, then close the old one**: closing it sends an "inactivated" webhook that must not read as the client cancelling. If closing fails, undo and close the new plan, so the client is never on two plans (never charged twice).
- Choosing the current plan while a cancellation is pending **resumes** it; choosing it while a change is scheduled **drops** the schedule (restore the renewal amount, or reopen a plan on the current interval).

### 7.5 Add-ons
Add: access now. Xendit: take the prorated price as a one-off payment (nothing is kept if it fails), then `PATCH` the plan amount (`pending ?? current` plan and interval, pack price added). Stripe: a new subscription item with `metadata[addon]`, prorated. **Remove**: one pack at a time; refuse while usage still needs it (storage used, active projects, workspaces; pooled across the account; respect staff overrides). Xendit: `PATCH` the amount down, no credit for the paid part of the period. Stripe: delete the item with `proration_behavior=create_prorations` (credit on the next invoice); find the item by `metadata.addon`. Write a `PlanChange` for both.

### 7.6 Cancel and resume
- **Cancel = at period end**, never immediately. Access runs to `current_period_end`.
- **Xendit**: set `cancel_at_period_end = true` (and clear any scheduled change) *first*, then deactivate the plan (so nothing more is charged); if deactivation fails, undo the flag. A **daily job** later sets `cancelled_at` for accounts whose flag is set and whose period end has passed.
- **Stripe**: the portal sets `cancel_at_period_end`; Stripe's `customer.subscription.deleted` ends it.
- **Resume** (Xendit): open a new plan on the closed plan's linked method (an inactive plan still lists its `payment_methods`, Verified), first charge on the renewal date. Not possible once the period has run out: the client subscribes again.

### 7.7 Changing the payment method
- **Stripe**: customer portal.
- **Xendit**: create a **replacement plan** with the same price and renewal date, **no method** and no immediate charge; Xendit answers `REQUIRES_ACTION` with a linking page (Verified). Store it as `xendit_replacing_plan_id`. When it turns `ACTIVE` (the client returns to `/billing?method=updated`, or the `recurring.plan.activated` webhook for that id) it takes over and the old plan is closed, with the same undo-on-failure rule as 7.4. Close an abandoned replacement before starting another.

### 7.8 Ending a plan
Set `cancelled_at`; the account goes through the same "cancelled" state staff use (data kept for a purge window). **Ignore** "inactivated" events that your own code caused: an unpaid checkout plan you closed (`pending` set), a cancellation you made (`cancel_at_period_end` set), a plan you replaced (the id no longer matches).

### 7.9 Invoices and the "upcoming charge"
- A real invoice exists **only when money was taken**: the webhook creates it, keyed by the provider's charge id.
- **Return sync / backfill**: read the paid charges straight from the provider (`GET recurring/plans/{id}/cycles`, status `SUCCEEDED`; the successful attempt's `action_id` is the payment request id you need for refunds) when the client returns after paying, and once whenever a plan has no invoice yet. Because the key is unique, a late webhook never duplicates. Do not announce an old payment found this way (`notify = false`).
- **Upcoming charge**: show it as a **Scheduled** row at the top of the invoice list (and on the staff list), computed from the next-invoice figures. It is **not** a database invoice, so refunds, revenue and counts are unaffected; it becomes a real invoice when paid.

### 7.10 Billing country from the IP
Detect PH vs everywhere else from the request IP (this project: the free `api.country.is`, cached 30 days per IP; private/loopback IPs look up the server's own public IP, so local development works). It is a **guess**: show it with a "Change" link, fall back to a dropdown if the lookup fails, and save it once. The IP leaves your server to a third party; mention it in your privacy notice, or use an offline database (MaxMind GeoLite2) if that matters.

### 7.11 Refunds
Approving a refund sends it to the provider **before** anything is marked refunded; if the provider refuses, nothing changes.
- **Stripe**: `POST refunds` with the invoice's `payment_intent`.
- **Xendit**: `POST refunds` with `payment_request_id`, `reference_id = refund-<id>` (unique), `amount` (pesos), `currency`, `reason` `DUPLICATE` or `REQUESTED_BY_CUSTOMER`. The answer is `PENDING`: e-wallet refunds finish later (Verified).
- **Later failure** (Docs): `refund.failed` → mark the refund `failed` with the reason, give the amount back to the invoice (a fully refunded invoice becomes `paid` again) and alert the staff. `refund.succeeded` needs nothing. A refund made by hand in the provider's dashboard is ignored.
- Credit notes (account credit) never touch the provider.
- Older invoices without a stored `xendit_payment_request_id`: look it up from the plan's cycles, and refuse with a clear message if it cannot be found.

### 7.12 The congratulations modal
Show it once after a first subscription or a move to a **higher** plan; never for downgrades, interval-only changes or add-ons. Backend: flash `upgraded = <plan>` on a successful upgrade (and treat `?checkout=success` with a paid plan as one); the page prop is read once (`session()->pull`). Frontend gotcha: **the billing page stays mounted when a plan change redirects back to it**, so watch the prop in an effect instead of reading it only in `useState`'s initial value; and strip `?checkout=success` from the URL after showing.

---

## 8. Webhooks

| | Stripe | Xendit |
|---|---|---|
| Route | `POST /webhooks/stripe` | `POST /webhooks/xendit` |
| Verify | header `Stripe-Signature: t=<time>,v1=<hex>`; `hash_hmac('sha256', "t.payload", secret)`; reject if older than 5 minutes | header `x-callback-token` must equal the dashboard's **webhook verification token** (`hash_equals`) |
| Secret not set | answer 503 | answer 503 |
| Bad signature | log a `rejected` event, answer 400 | log a `rejected` event, answer 400 |

Rules that apply to every provider:

1. **CSRF-exempt** the route, but verify before reading anything.
2. **Store every event first** (`firstOrCreate` on a unique key) and only process it when it was just created or its status is `failed`. Key = `event id`, or `object id|event type` where one object sends several events.
3. **Return 500 when processing fails** (the provider retries) and record the error on the event. Return 200 for events you deliberately ignore (not ours, or caused by us).
4. **The webhook URL must be `https`** (Xendit and HitPay refuse otherwise). For local work use a tunnel (`ngrok`, Cloudflare Tunnel) and set `APP_URL` to it. Without one, the return sync still switches plans on and records invoices; only renewals, failures and refund notices need the webhook.
5. Look the account up by the **provider id** (plan, subscription, customer), not by trusting metadata.
6. Keep a "webhook log" page for staff: when something looks wrong, the answer is usually there.

Events this project handles:

- Stripe: `checkout.session.completed`, `customer.subscription.created|updated|deleted`, `invoice.*`, `charge.refunded`, `charge.dispute.*`.
- Xendit: `recurring.plan.activated`, `recurring.cycle.succeeded`, `recurring.cycle.failed`, `recurring.plan.inactivated`, `refund.failed`, `refund.succeeded` (Docs: names and fields are from Xendit's documentation; confirm with a real delivery). `recurring.cycle.created` / `retrying` are stored and need nothing.

## 9. Environment and configuration

```env
STRIPE_API_KEY=            # sk_test_... / sk_live_...
STRIPE_PUBLISHABLE_KEY=
STRIPE_WEBHOOK_SECRET=     # whsec_...
XENDIT_SECRET_KEY=         # xnd_development_... (test) / xnd_production_... (live)
XENDIT_CALLBACK_TOKEN=     # Settings > Webhooks > Webhook verification token
```

Config in `config/services.php` (`services.xendit.secret_key`, `services.xendit.callback_token`). Xendit tells test and live apart **by the key**, so there is no sandbox switch. When the key is absent, `provider()` returns `stripe` and the app behaves as before. In tests, blank the keys in `phpunit.xml` so a developer's real `.env` never leaks into a test.

---

## 10. Stripe reference (what this project calls)

All requests: `POST` form-encoded to `https://api.stripe.com/v1/`, secret key as Basic-auth user.

| Purpose | Call |
|---|---|
| Customer | `POST customers` |
| Checkout | `POST checkout/sessions` with `mode=subscription`, `customer`, `line_items[0][price_data][currency|unit_amount|recurring][interval]=month|year`, `line_items[0][tax_rates][0]`, metadata on the session **and** `subscription_data[metadata]` |
| VAT | create a 12% **exclusive** tax rate once (`tax_rates`), reuse its id |
| Plan change | `POST prices` (price_data) then `POST subscriptions/{id}` with `items[0][id]`, `items[0][price]`, `items[0][quantity]=1`, `proration_behavior=create_prorations`; clear a pending cancel with `cancel_at_period_end=false` |
| Add-on | `POST subscription_items` (`subscription`, price_data recurring, `metadata[addon]`) |
| Remove add-on | `GET subscriptions/{id}` find the item by `metadata.addon`, `DELETE subscription_items/{id}` with `proration_behavior=create_prorations` |
| Next invoice | `POST invoices/create_preview` |
| Portal | `POST billing_portal/sessions` (change card, cancel, download invoices) |
| Refund | `POST refunds` with `payment_intent` (or `charge`) and `amount`; newer API versions list the payment on `invoice_payments` |

An annual Stripe price is `unit_amount = monthly × 12` with `recurring[interval]=year`.

## 11. Xendit reference (what this project calls)

Base `https://api.xendit.co/`, **JSON** in and out, secret key as the Basic-auth user with an **empty password**, **amounts in decimal pesos**.

| Purpose | Call | Status |
|---|---|---|
| Customer | `POST customers` (`reference_id` unique, `type=INDIVIDUAL`, `email`, `individual_detail.given_names`) | Verified |
| Plan (checkout) | `POST recurring/plans` as in 7.1; answers `202`, `REQUIRES_ACTION`, an `AUTH` action URL | Verified |
| Read a plan | `GET recurring/plans/{id}`: `status` (`REQUIRES_ACTION`, `ACTIVE`, `INACTIVE`), `payment_methods[]`, `schedule` | Verified |
| Edit price | `PATCH recurring/plans/{id}` (`amount`, `description`, `metadata`) | Verified |
| Close a plan | `POST recurring/plans/{id}/deactivate` | Verified |
| Charges of a plan | `GET recurring/plans/{id}/cycles`: `status` (`SUCCEEDED`, `SCHEDULED`, `CANCELLED`), `type` (`IMMEDIATE`, `SCHEDULED`), `attempt_details[].action_id` = the payment request id | Verified |
| Reusable wallet link | `POST v2/payment_methods` (`type=EWALLET`, `reusability=MULTIPLE_USE`, `ewallet.channel_code` = `GCASH`, `PAYMAYA`, `SHOPEEPAY`) accepted in the sandbox, `REQUIRES_ACTION` with an `AUTH` link | Verified |
| Refund | `POST refunds` (`payment_request_id`, `reference_id`, `amount`, `currency`, `reason`) answers `PENDING` | Verified |
| One-off charge on the linked method (prorated upgrade or add-on) | `POST payment_requests` with header `api-version: 2024-11-11`: `reference_id`, `currency`, `amount`, `payment_method_id` (from the plan's `payment_methods[0]`), `capture_method=AUTOMATIC`. Treat only `status = SUCCEEDED` as paid; the answer's `id` (`pr-...`) is what a refund needs | **Docs** (not run against a sandbox: e-wallet tokens may answer `PENDING`; test GCash, Maya, ShopeePay and a card) |
| Webhook events | see section 8 | Docs |

Behaviour worth knowing:

- **First charge** (`immediate_action_type = FULL_AMOUNT`): taken the moment the client links a method (cycle 1, type `IMMEDIATE`); the next cycle is `SCHEDULED` at `anchor_date`. No double charge. A plan opened on an already-linked method with *no* `immediate_action_type` is `ACTIVE` with no charge until its anchor.
- **Yearly** = `interval: MONTH`, `interval_count: 12`.
- **Failed charge**: retried as configured; with `failed_cycle_action = STOP` the plan goes `INACTIVE` when retries run out.
- **An inactive plan keeps its `payment_methods`**, so a closed plan can be re-opened on the same method.
- **Tiny amounts are refused** in the sandbox (₱10 returned 400). Test with realistic amounts.
- **No list filter by plan on `/recurring/cycles`**; use `recurring/plans/{id}/cycles`.
- **Live accounts**: business verification takes up to 14 days and needs company documents; **auto debit for GCash, Maya and ShopeePay may need manual activation by Xendit sales** (help@xendit.ph). Confirm which methods can auto-renew on your live account and the fees **before** promising them to clients. The sandbox accepting a call is not proof that live does.
- **Webhook URLs need https.** Register them under Settings > Webhooks (the page offers a newer version for newer products).

---

## 12. Gotchas collected while building this

1. **Ask the provider about recurring per method and per country** before choosing it (the HitPay mistake). One-time payment support is not recurring support.
2. **Two sources of truth go out of step.** Make every state change idempotent and keyed by the provider's id, and run the same function from the webhook and the return sync.
3. **Order matters when you replace a plan.** Update your record first, then close the old plan; set the cancel flag before deactivating; clear the pending marker only when the first payment is confirmed. Otherwise your own "inactivated" webhook ends the account.
4. **`Http::fake` keeps the first answer** for a URL; a second fake in the same test is ignored. Swap in a fresh client between steps (`Http::swap(new Factory)`).
5. **`Http::preventStrayRequests()` breaks Inertia SSR's local health call.** Give tests a default fake for the provider's endpoints instead.
6. **SQLite cannot drop a column that has a unique index**; drop the index first (matters for rollbacks in local development).
7. **Do not send an event to another system if its contract rejects the value.** Here the FBS Suites hub only accepts `payment.provider` = `stripe|paymongo|manual`, so Xendit payments are not reported to it until the contract adds `xendit` (update the shared architecture repo first).
8. **Pesos vs centavos**: Stripe and your database use integers, Xendit uses decimals. Convert in one function.
9. **A refund is not settled when it is approved** (Xendit answers `PENDING`). Handle the later failure.
10. **The modal bug**: a page that stays mounted does not re-run `useState` initial values. Watch props with an effect.
11. **MRR drifts** unless every plan change and add-on change writes a `PlanChange`, for every provider and every code path (including the Stripe path that used to skip it).
12. **Dev data lies.** Test accounts, simulated payments and sandbox charges all count in the dashboard. Say so on the dashboard in non-production, or reset the test accounts.

13. **Xendit cannot prorate**, so a plan edit that raises the amount does not charge the difference. Charge it yourself, before changing anything, and treat only a confirmed payment as success.
14. **A scheduled interval switch moves the Xendit plan now** (a new plan whose first charge is the renewal) while your plan and interval stay the old ones until the renewal is paid: keep the two apart (`pending_interval` is the Xendit plan's interval), or a later upgrade edits the wrong plan.

## 13. Tests worth having (all with faked HTTP)

Per provider: checkout builds the right payload (amount with VAT, interval, return URL); the plan stays off until payment is confirmed; return sync switches it on (and not while still waiting); webhook auth (503 / 400 / ok); a succeeded cycle switches on and records **one** invoice even when delivered twice; a later cycle is a second invoice and moves the period end once; failed cycle starts the steps; inactivated ends it **but not** after a cancellation or an unpaid checkout you closed; upgrade (access now, prorated one-off payment of the right share, renewal amount edited, invoice recorded, nothing changed when the payment is not confirmed, access kept when the amount edit fails after payment); downgrade (no payment, renewal amount lowered, plan unchanged until the renewal cycle succeeds, then changed once; choosing the current plan or cancelling drops it); same plan and too-many-users refused; interval switch (scheduled: new plan on the linked method, old closed, undo when closing fails, refused without a method, starts with the renewal and the period runs the new interval); add and remove an add-on (prorated payment, renewal amount, `PlanChange`, refused when the payment fails, not owned, still needed, or while cancelled); cancel and resume; change payment method (replacement, takeover, abandoned, undo); next invoice and the Scheduled rows; refund (sent to the provider, a decline changes nothing, older invoice looked up, credit note sends nothing, later failure undoes it); provider choice by country; upgrade modal flag (upgrade yes, downgrade / interval / add-on no, once only). Also run the *existing* Stripe suite after every change.

## 14. Go-live checklist

- [ ] Live keys set in the production environment only (never in chat or the repo); `.env.example` lists the names.
- [ ] Webhook URLs registered on both providers, `https`; secrets set; a test delivery shows `processed` in the webhook log.
- [ ] Stripe: live tax rate, portal configured (cancel, update card), smart retries on, `invoice.upcoming` days set.
- [ ] Xendit: business verified; auto debit active for the methods you advertise; fees checked; notification emails on; test a real small charge and a refund.
- [ ] Xendit: test an **upgrade** (prorated one-off charge on each method you offer: GCash, Maya, ShopeePay, card) and read what `payment_requests` answers (`SUCCEEDED` vs `PENDING`); confirm the `api-version` header is accepted.
- [ ] The scheduler runs (`billing:dunning` and the Xendit cancellations job, daily) on one server.
- [ ] Email works for receipts, failed payments and renewal notices.
- [ ] Billing page and terms say the same per provider (upgrade prorated now; downgrade and interval switch at renewal on Xendit; Stripe prorated on the next invoice).
- [ ] Privacy notice mentions the IP lookup (or switch it to an offline database).
- [ ] Hub / accounting integrations accept the new provider names.
- [ ] A staff page shows webhook failures, upcoming charges, refunds and failed payments.
- [ ] One end-to-end test per provider in production with a real small amount, then a refund.

## 15. Prompt to start the same work in another project

> Add monthly and yearly subscriptions. Plans are one fixed price per plan (store the per-month price for both intervals; annual = ×12 charged once a year), VAT only for the Philippines, add-ons priced per month. Stripe for non-PH, Xendit recurring plans for PH. Follow `integrations/subscriptions-stripe-xendit.md` in the Web-Development-Architecture repo: provider class per provider with the same verbs, webhooks stored then processed idempotently (unique provider key), a return sync that works without a public webhook, upgrades start now and are prorated (Xendit: one-off payment on the linked method, charged first), downgrades and interval switches start at the next renewal (`pending_plan` / `pending_interval`), cancel at period end, replace-then-close for plan swaps, refunds sent to the provider before marking, a `PlanChange` row for every plan or add-on change, and tests with faked HTTP for each flow. Do not send provider names to other systems until their contract accepts them.

## 16. Where the code lives in the FBS Work project

`app/Support/Stripe.php`, `Xendit.php`, `XenditBilling.php`, `Billing.php`, `IpCountry.php`; controllers `SubscriptionController`, `WorkspaceController::checkout`, `WebhookController`, `RefundsController`, `PaymentsController`; models `Workspace` (prices, VAT, MRR, add-on limits), `Invoice`, `Refund`, `PlanChange`; page `resources/js/pages/billing.tsx` and `components/upgrade-celebration.tsx`; migration `2026_12_01_000000_add_pending_plan_change.php`; tests `tests/Feature/XenditBillingTest.php`, `SubscriptionTest.php`, `RefundsTest.php`. Project status and open items: `docs/xendit-handoff.md`.
