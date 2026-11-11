# Stances

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/ernestdefoe/stances.svg)](https://packagist.org/packages/ernestdefoe/stances)

A [Flarum](https://flarum.org) 2 extension: one-click blocks anywhere in a post, and the side each member took shown beside their replies.

- **Four block types:** Agree / Disagree, Yes / No, This or That (with an optional picture each) and a 1–5 star Rating. Members vote with one click, in place.
- **Anywhere in a post:** add blocks from the composer; each sits where its `[stance=…]` line is. Up to 3 per post by default.
- **Results** always, after voting, or after it closes. An optional close date. Votes can be changed or taken back, per block. Anonymous blocks never show who voted, to anyone.
- **Stance badges:** what each member chose shows beside their replies in that discussion, with a "changed" tag when they switched sides after replying. "Show replies from" filters the discussion to one side.
- **Explain my vote** starts a reply that mentions the block's post and says what you chose.
- **Featured block** under the opening post, and a **Stances page** with Needs your vote, Trending, New and Closing soon.
- **Moderation:** per-tag permissions, close, reopen, reset, make anonymous, feature, pick the badge block.
- **FoF Badges metrics** (blocks voted in, blocks added), when FoF Badges is installed.

## Installation

```sh
composer require ernestdefoe/stances
```

## Updating

```sh
composer update ernestdefoe/stances
php flarum migrate
php flarum cache:clear
```

## Links

- [Packagist](https://packagist.org/packages/ernestdefoe/stances)
- [GitHub](https://github.com/ernestdefoe/stances)
- [Support](https://ernestdefoe.online)

☕ [Buy me a coffee](https://buymeacoffee.com/ernestdefoe)
