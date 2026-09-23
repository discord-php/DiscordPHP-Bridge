# DiscordPHP-Bridge

The half of a Discord chat bridge that does not know which network it is
bridging to.

Routing, persistence, one command catalogue served to every chat, Components v2
panels, and the rate limiting — with no mention of Twitch, Telegram or anything
else. A network plugs in as a **connector**, in its own composer package.

```
#general  ──────────►  twitch.tv/twitchdev
          ◄──────────
          ──────────►  t.me/mygroup
          ◄──────────
```

## Why this exists

Two bots that each bridged Discord with one other network turned out to be the
same bot twice. `Links`, `Store`, `BridgeCheck`, `RateLimiter`, `Permissions`
and `WebhookDelivery` were the same class written in two repositories with the
target renamed, and `Filesystem` was byte-identical. This is that half, with the
platforms taken out of it — so a third network is a new package rather than a
third fork.

## Writing a connector

Implement [`Bridge\Connector`](src/Bridge/Connector.php). It owns its client,
its socket, its authentication and its idea of what a room is, and hands the
core [`Incoming`](src/Bridge/Message/Incoming.php) messages and
[`Room`](src/Bridge/Room.php) descriptions.

```php
final class TwitchConnector implements Connector, ProvidesActions
{
    public function name(): string    { return 'twitch'; }   // also the slash command
    public function label(): string   { return 'Twitch'; }
    public function surface(): Surface { return new Surface('twitch', 'Twitch', 500, markdown: false, lines: false, prefix: '!'); }

    public function boot(Bot $bot): void          { /* build the client, before the first message */ }
    public function start(): PromiseInterface     { /* connect; reject if it cannot */ }

    public function relay(string $target, Outgoing $message): PromiseInterface { /* render, then say */ }
    public function resolve(string $target): PromiseInterface                  { /* ?Room */ }
    public function normalise(string $input): ?string                          { /* "twitch.tv/X" -> "x" */ }
    // … sync, send, onIncoming, joined, queued, stop
}
```

Optional interfaces say what the network can do, so the relay asks instead of
assuming: `Capability\Editing` (rewrite a relayed message), `Capability\Media`
(carry a picture, and hand over a file's bytes for Discord), `Capability\Avatars`
(a sender's picture for the Discord copy), `Capability\ProvidesActions` and
`Capability\ProvidesModules`.

`start()` returning a promise is not a formality. A connector that rejects is
logged, reported to the owner, left out of the startup check, and stops the
command prune pass — so a network that failed to come up cannot get its
commands deleted from Discord.

Commands typed in the network's own chat go to
[`ChatDispatcher`](src/Bridge/Command/ChatDispatcher.php): the connector says
who is asking, where, and at what rank, and the core does the rest — the same
access checks, [cooldowns](src/Bridge/Command/Cooldowns.php) and refusals as
every other surface. A command for *another* network acts on the room that
shares a Discord channel with this one, and is refused rather than guessed when
there is more than one.

Registering it is one line, and it arrives with its configuration commands
already written:

```php
$bot->addConnector(new TwitchConnector($config));
```

## Commands are always qualified

A name is only free because no connector has claimed it yet. `title` belongs to
Twitch today and to something else the moment a fourth network arrives — and
since every connector's commands are offered on *every* surface, an unqualified
catalogue is one installed package away from two commands answering to the same
word, with the loser looking broken rather than shadowed.

So every command carries the connector that owns it, and
[`ActionRegistry`](src/Bridge/Command/ActionRegistry.php) refuses a collision
rather than letting the last one registered win.

```
/bridge   help | about | list | status                    the core's own

/twitch   link | here | unlink | list | status | reset     every connector gets these
          channel  title | game | tags | info              whatever it brings
          mod      ban | unban | timeout | …
```

An action is a leaf. Where it sits is its **qualifier** and its **group**, and
each surface renders what it has room for:

| Surface | Form |
| --- | --- |
| Discord slash | `/twitch channel title text:Back in ten` |
| Discord prefix | `!twitch channel title Back in ten` |
| Any other chat | `!twitch title Back in ten` |

Discord caps a command at 25 options and allows one level of sub-command group,
which is the only reason groups exist. A chat has no such cap, so it drops the
group — never the qualifier.

The six bridge verbs are defined once, in
[`BridgeActions`](src/Bridge/Actions/BridgeActions.php), against whatever
connector they are given, so they cannot be worded differently on two networks.
All of them are limited to whoever the server belongs to, and that gate is the
security model for the whole project: whoever can run `link` decides which
Discord channel gets copied into a public chat somewhere else.

## Surviving a restart

Every bridge lives in one JSON file, grouped by connector:

```json
{ "version": 2,
  "links":  { "twitch": { "<guild>": { "<channel>": "coffeescrafts" } } },
  "labels": { "telegram": { "-1001234567890": "My Group" } } }
```

[`JsonFile`](src/Bridge/Support/JsonFile.php) is what keeps it:

- **Writes are atomic** — content goes to a temp file and is renamed over the
  target, so a crash mid-write cannot leave a half-written file where the
  configuration was.
- **The last good copy is kept** beside it as `.bak`, written *after* each save,
  not by copying the file about to be replaced.
- **A damaged file is never silently replaced.** If the JSON does not parse the
  backup is tried; if that fails too, the file is preserved as
  `.corrupt-<timestamp>` and the bot starts empty rather than overwriting it on
  the next `link`.
- **Entries of the wrong shape are dropped, not loaded**, so a hand-edited file
  cannot take the bot down — and the good entries in it still survive the next
  write.

A file written by a single-platform bot has no `version` and no connector level;
it is migrated on load, so an existing installation keeps its bridges with
nothing to re-run.

Ten seconds after startup the bot checks what it restored: can it still see each
Discord channel, does each room still exist, and — the one a restart is
specifically meant to re-establish — is it actually *in* that room? A join that
silently failed leaves a bridge that works in one direction only. Findings go to
the log, to the owner's DMs, and to the foot of `list`. Nothing is pruned
automatically: a guild can be briefly unavailable during an outage, and deleting
somebody's configuration over a bad ten seconds is worse than telling them.

### Disk I/O and the event loop

A blocking write stops the loop: while it runs, no heartbeat is sent and nothing
is relayed. Saves therefore go through
[react/filesystem](https://github.com/reactphp/filesystem) — which performs them
off the loop **only** with `ext-uv` (Linux, macOS and Windows) or `ext-eio`
(POSIX). With neither, that library's own fallback is `file_put_contents()`
wrapped in an already-resolved promise, so the bot does the write itself instead
and, since it is blocking anyway, blocks *properly*: `fflush()` and `fsync()`,
which `putContents()` cannot express. Measured on a Windows host: 3.5 ms
blocking, 0.07 ms with an async backend. The bot logs which one it picked at
startup.

Either way the caller never waits — `link` answers from memory, the write is
queued, rapid changes coalesce into one write, and Ctrl-C flushes anything
outstanding before the loop stops.

## Rate limits

Discord allows [50 requests per second per token][limits], publishes per-route
buckets through `X-RateLimit-Bucket`, and treats 10,000 rejected requests in ten
minutes as grounds for a Cloudflare ban on the whole host — which is not a
throttle anybody recovers from by waiting.

Merging two bots into one changed the arithmetic: two applications with two
tokens and two budgets became one. Five rules follow.

1. **One `Discord\Http`, always.** It holds the buckets and the concurrency cap,
   so a second client would keep its own empty bucket table and race the first
   into 429s against the same token. Connectors use DiscordPHP's parts and
   repositories, which already route through it —
   [a test](tests/SingleHttpClientTest.php) scans the core and every installed
   connector for anything that does not.
2. **Relayed chat is paced per destination channel** by
   [`OutboundPacer`](src/Bridge/Relay/OutboundPacer.php), sized to Discord's
   five-per-five-seconds message sublimit and shared by every connector. One
   message on a busy network fans out to every channel following that room, and
   two connectors relaying into the same channel each stay under the limit alone
   and breach it together.
3. **Command replies stay on interaction endpoints**, which Discord documents as
   exempt from the global limit, so configuration keeps answering while the
   relay is saturated.
4. **A 403 is never retried.** A channel the bot cannot create a webhook in is
   remembered, so a missing permission costs one rejected request rather than
   one per relayed message.
5. **Boot writes nothing it doesn't have to.**
   [`CommandSync`](src/Bridge/Support/CommandSync.php) compares each definition
   against what Discord already has, leniently enough that the fields Discord
   adds on the way back do not read as a change.

[limits]: https://docs.discord.com/developers/topics/rate-limits

## Loop prevention

A bridge that repeats itself is an infinite loop that gets the account banned
from both networks within minutes. Each direction drops its own output as early
as it can:

- **Discord → elsewhere** ignores any message carrying a `webhook_id`, and any
  message from a bot. Relayed chat arrives *through* a webhook, so that first
  rule is the one doing the work. Other bots are dropped deliberately: two
  bridges in one channel would otherwise ping-pong forever.
- **Elsewhere → Discord** ignores anything the connector marks as its own. Only
  the connector can recognise its own voice, so only it can set that flag.

Commands are dropped in both directions too — `!twitch title something` is an
instruction to the bot, not a remark. Only a *registered* command counts, so
ordinary chat full of `!` still relays.

## Layout

```
src/Bridge/
  Bot.php                      one gateway, one Http, however many connectors
  Connector.php                what a platform implements
  Config.php  Environment.php  settings, from the environment only
  Store.php  Links.php         persistence, and the routing table
  Room.php
  Actions/                     the six bridge verbs, and /bridge
  Capability/                  Editing, Media, ProvidesActions, ProvidesModules
  Command/                     Action, Context, Access, Surface, and the adapters
  Message/                     Incoming, Outgoing, Media
  Relay/                       both directions, webhooks, pacing, edit mapping
  Builders/  Helpers/          Components v2, custom_id routing
  Modules/                     the escape hatch from a string-in string-out action
  Support/                     text, permissions, async disk I/O, the startup check
```

The logic worth testing is deliberately pure — routing, parsing, sanitisation,
pacing, the permission ladder and the command tree are all verifiable without a
socket:

```bash
composer test
```

## Licence

MIT.
