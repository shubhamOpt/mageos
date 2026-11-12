# Mage-OS Storefront Assistant

A chat assistant for the Mage-OS storefront, built during a hackathon. Signed-in customers can see their last order or start a return with one click, or ask the same things in plain words.

This repository is a standard **Mage-OS 4.0.0** project (based on Magento Open Source 2.4.9), run locally with DDEV. The hackathon work is the custom module in [`app/code/Vendor/AIAssistant/`](app/code/Vendor/AIAssistant/).

## What it does

An **Ask us** button sits in the bottom-right corner of every storefront page. It opens a chat panel with two clickable actions:

| Button | Typed command | Also understands | Reply |
|---|---|---|---|
| **Show my last order** | `/lastorder` | "show my last order", "what is my latest order?" | Order number, date, status, total, shipping method, items, and a link to the order page |
| **Return last order** | `/returnlastorder` | "return my last order", "I want to return the last order" | Whether the order can be returned and which items, with a link to the return form with the order already selected |

Any other question goes to an AI model, which can look up the same two things itself. For example, it can answer "I want to send back what I bought last time" with the returnable items and the return link.

### How a return is checked

The **Return last order** action applies the store's own return rules, using the eligibility check from the Mage-OS returns module (`MageOS_RMA`). It gives a different answer for each case:

- returns are turned off in the store;
- the order's status isn't one that allows returns (by default only *Complete*);
- the return period has ended (30 days after shipping by default);
- everything has already been returned or requested;
- the order is eligible: the returnable items are listed, with a link to the return form.

The chat never creates a return itself. The customer chooses the items, reason, and refund or exchange on the returns module's own form.

## How it works

```
Chat widget (jQuery + RequireJS)
   │  POST /aiassistant/chat/ask   (form key required)
   ▼
Ask controller ── recognises a command? ──yes──▶ answered straight from the database
   │                                              (instant, no AI cost, works if the AI is down)
   no
   ▼
Assistant ──▶ AI model via MageOS_AiBase
   ▲    │  model asks to run a tool
   │    ▼
   └── get_last_order / check_last_order_return
```

- **Commands don't need AI.** The buttons and typed commands are answered directly from the database.
- **AI only for free-form questions.** These use the AI provider configured in Mage-OS's AI Base module. The model has exactly two read-only tools, and any other tool name it asks for is refused.
- **Customers only see their own orders.** The customer is always identified from their server-side login session, never from the message or the AI.
- **Replies are plain text.** They're shown with `.text()`, so nothing in a reply can inject HTML into the page.
- **Page caching still works.** The widget is the same on every page and the conversation runs over AJAX, so full page cache is unaffected.

### Files

| File | Purpose |
|---|---|
| [`Model/LastOrderProvider.php`](app/code/Vendor/AIAssistant/Model/LastOrderProvider.php) | Finds the customer's newest order in the current store and summarises it |
| [`Model/ReturnLastOrderProvider.php`](app/code/Vendor/AIAssistant/Model/ReturnLastOrderProvider.php) | Checks the last order against the return rules and builds the return-form link |
| [`Model/Assistant.php`](app/code/Vendor/AIAssistant/Model/Assistant.php) | Recognises commands, runs the AI conversation and its tools |
| [`Controller/Chat/Ask.php`](app/code/Vendor/AIAssistant/Controller/Chat/Ask.php) | The chat endpoint |
| [`view/frontend/templates/widget.phtml`](app/code/Vendor/AIAssistant/view/frontend/templates/widget.phtml) | Chat panel and the two buttons |
| [`view/frontend/web/js/chat.js`](app/code/Vendor/AIAssistant/view/frontend/web/js/chat.js) | Sends messages and shows replies |
| [`view/frontend/web/css/source/_module.less`](app/code/Vendor/AIAssistant/view/frontend/web/css/source/_module.less) | Widget styling |

The module depends on `MageOS_AiBase`, `MageOS_RMA`, `Magento_Customer` and `Magento_Sales`, all of which ship with Mage-OS 4.0.0.

## Run it locally

Requirements: [DDEV](https://ddev.com/) and Docker.

```bash
git clone https://github.com/shubhamOpt/mageos.git
cd mageos
ddev start
ddev composer install
```

Install Mage-OS with sample data, or import a database dump, then:

```bash
ddev magento module:enable Vendor_AIAssistant
ddev magento setup:upgrade
ddev magento cache:clean
```

Secrets and generated files aren't in git: `app/etc/env.php`, `vendor/`, `generated/`, `var/`, `pub/static/` and uploaded media.

### Try it

1. Sign in at `https://mageos.ddev.site/customer/account/login` as the sample data customer:
   - **Email:** `roni_cost@example.com`
   - **Password:** `roni_cost3@example.com`

   These are Magento's public sample data credentials.
2. Click **Ask us**, then **Show my last order**.
3. For **Return last order**, first turn on returns:

   ```bash
   ddev magento config:set rma/general/enabled 1
   ddev magento cache:clean config
   ```

   The customer's last order must be *Complete* (invoiced and shipped) and shipped within the return period. Sample data orders aren't *Complete*, so create and fully ship an order first.
4. For free-form questions, configure an AI provider and API key in Mage-OS's AI Base settings. The two buttons work without one.

## What was tested

Tested locally against customer `roni_cost@example.com`:

| Check | Result |
|---|---|
| Command recognition for buttons, typed commands and plain phrases | Correct for all tested phrases |
| Last order, signed in | Correct summary and order link |
| Guest, and customer with no orders | Asks to sign in / says there are no orders |
| Return: returns turned off, order status not allowed, eligible order | Correct reply for each |
| Free-form questions via the AI | Correct answers from the tool results |
| Endpoint without or with a wrong form key | Rejected |
| Both buttons on the storefront homepage | Present |

The return period expired and everything-already-returned cases are implemented but weren't tested, because no order fitted them. The order lookups were tested directly in PHP and the endpoint as a guest over HTTP; a signed-in browser session wasn't tested end to end.

## Ideas for later

- Rate limiting, to stop bots running up AI costs.
- Splitting each chat action into its own installable package, so stores can pick which actions they offer.
- More actions, such as tracking a shipment or reordering.
