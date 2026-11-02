# Architectuur van ratings-and-reviews

Dit document is het bouwcontract voor het pakket. Het volgt uit de [inventarisatie](inventarisatie-2026-11.md) en de beslissingen van 2 november 2026. Wijzigingen in dit contract worden hier eerst vastgelegd en pas daarna gebouwd.

## Uitgangspunten

- **Eén Laravel-pakket** `jw-1980/ratings-and-reviews`, namespace `RatingsAndReviews\`, met auto-discovery via `extra.laravel.providers`.
- **Platform:** PHP `^8.3`, Laravel `^12.0 || ^13.0`, `symfony/html-sanitizer` `^7.2 || ^8.0`. Er komen geen andere runtime-dependencies bij.
- **Database:** MySQL 8.4 is het doel en de testomgeving. Er worden geen SQLite-tests geschreven of gerapporteerd als bewijs (NC-standaard, hoofdstuk 7). Er wordt geen Docker gebruikt.
- **Geen AI.** Elke feature is deterministisch: regels, woordenlijsten, tellingen of statistiek.
- **De host-app blijft eigenaar van** gebruikers, rechten (RBAC), punten en badges, notificatiekanalen, productdata, prijzen, lijsten en het admin-scherm in de eigen huisstijl. Het pakket vraagt dat op via **contracts** en meldt wat er gebeurt via **events**.
- **Morph map.** Het pakket registreert zijn eigen modellen met korte aliassen (`rr_review`, `rr_comment`, …). De host-apps gebruiken `Relation::enforceMorphMap()` voor hun eigen modellen.
- **Vertalingen:** nl en en in `lang/`, namespace `ratings-and-reviews::`. Er staan geen hardcoded teksten in PHP of views.
- **Versies:** semver-tags; de eerste release wordt `v0.1.0`.

## Mappenstructuur

```
config/ratings-and-reviews.php
database/migrations/
lang/{nl,en}/
resources/views/components/      Blade-componenten (WelZoLekker)
resources/js/                    gedeelde Tiptap-configuratie en regels (ES-modules)
routes/api.php                   JSON-API, prefix instelbaar, middleware instelbaar
src/
  Contracts/                     door de host te implementeren
  Events/
  Models/
  Services/
  Http/{Controllers,Requests,Resources}
  Policies/ of Abilities
  Support/                       waarde-objecten, enums
  RatingsAndReviewsServiceProvider.php
tests/                           Orchestra Testbench + PHPUnit op MySQL 8.4
packages/vue/                    npm-pakket @jw-1980/ratings-and-reviews-vue (NutriCompare)
```

## Contracts (door de host geïmplementeerd)

| Contract | Doel |
|---|---|
| `Reviewable` / `Commentable` | Op het model van de host (product, additief, merk, …). Bepaalt de sectie, of het subject open staat, wie het mag zien en een URL en titel. Traits `HasReviews` en `HasComments` leveren de relaties. |
| `AuthorResolver` | Leest weergavenaam, avatar en profiel-URL van een `user_id`. Voedt ook de **profiel-hovercard**. |
| `AbilityResolver` | Koppelt pakket-abilities (`rr.moderate`, `rr.manage-settings`, `rr.manage-topics`) aan de RBAC van de host. |
| `RiskSignals` | Bepaalt of een stem of bijdrage telt (`counted` met `uncounted_reason`) en of een bijdrage moet wachten. Beide apps implementeren dit met `device-id`. Standaard: alles telt. |
| `VoterIdentity` | Levert de `voter_key` voor leden en gasten (`u:<id>` of `g:<hmac>`), plus `network_hash` en `device_hash`. |
| `PriceAtReviewResolver` | Optioneel. Prijs in centen en valuta van een subject op een datum. |
| `CardResolver` | Optioneel. Rendert een **productkaart** in de editor voor `type:id`. Geeft titel, URL, afbeelding, prijs en score terug. |
| `WantToTryList` | Optioneel. Zet een subject op de lijst van de gebruiker ("Wil ik proberen"). |
| `ContributionCounter` | Telt goedgekeurde bijdragen per gebruiker, voor **vertrouwensniveaus**. Standaard: tellen in de pakkettabellen. |

Elk optioneel contract heeft een standaardbinding die de feature uitschakelt. De UI verbergt de feature dan.

## Datamodel (tabelprefix `rr_`)

- `rr_section_settings`: `section` (uniek), `settings` (json), `updated_by`, timestamps. Instellingen per sectie, met defaults uit de config. Velden:
  - diepte en editor-tools;
  - stemdimensies en gaststemmen;
  - sortering, inklappen en topreactie;
  - minimale lengte en bewerktermijn;
  - vertrouwensniveaus;
  - slow-mode-standaard;
  - drempel voor de TL;DR;
  - reviewschaal en uitgebreide velden.
- `rr_rating_topics`: `scope`, `category_key` (nullable), `parent_id` (overerving), `name` (json nl/en), `scale_max` (5 of 10), `sort_order`, `is_active`, timestamps. Maximum actief per scope en categorie uit de config (standaard 4), bewaakt met `lockForUpdate`.
- `rr_reviews`:
  - Subject en auteur: `reviewable_type`/`_id`, `user_id`, `status` (pending, approved, rejected, spam).
  - Score en tekst: `rating` plus `scale_max`, `title`, `body_html`, `body_text`, `pros` en `cons` (json).
  - Features: `facets` (json), `price_cents`, `price_currency`, `compared_type`/`_id`, `comparison` (better/worse/same), `quality_score`.
  - Tellers en herkomst: `helpful_count`, `flags_count`, `ip_hash`, `network_hash`, `device_hash`, `counted`.
  - Tijden: `approved_at`, `edited_at`, soft deletes.
  - Unique (reviewable, user).
  - **Een afgewezen of als spam gemarkeerde review kan niet automatisch terug naar approved.** Opnieuw indienen geeft weer `pending`.
- `rr_review_topic_ratings`: `review_id`, `topic_id`, `value`, `scale_max`, unique (review, topic).
- `rr_comments`:
  - Plaats in de draad: `commentable_type`/`_id`, `section`, `parent_id`, `root_id`, `depth`, `user_id`, `status`.
  - Inhoud: `body_html`, `body_text`, `quoted_comment_id`, `quote_text`.
  - Tellers: `agree_count`, `disagree_count`, `helpful_count`, `unhelpful_count`, `needs_source_count`, `helpful_score` (stored generated), `controversy_score` (decimal, bij elke hertelling bijgewerkt), `flags_count`.
  - Status en tijden: `is_edited`, `edited_at`, `author_removed_at`, `held_as_evidence`, `publish_at` (venster om ongedaan te maken), `published_at`.
  - Herkomst: `ip_hash`, `network_hash`, `device_hash`, soft deletes.
  - Indexen op (commentable, status, parent_id, helpful_score) en een FULLTEXT op `body_text`.
- `rr_comment_revisions`: `comment_id`, `revision`, `body_html`, `body_text`, `edited_by`, `edited_at`.
- `rr_votes`: `votable_type`/`_id`, `dimension` (agreement, helpfulness, needs_source), `value` (−1, +1), `user_id` (nullable), `voter_key`, `network_hash`, `device_hash`, `counted`, `uncounted_reason`, timestamps. Unique (votable, dimension, voter_key). Een stem wordt nooit geweigerd, alleen niet meegeteld.
- `rr_reports`: `reportable_type`/`_id`, `reporter_id`, `reason` (uit config), `details`, `snapshot` (json), `status` (open, resolved, dismissed), `handled_by`, `handled_at`, `resolution_note`. Eén open melding per melder en item.
- `rr_discussion_states`: `commentable_type`/`_id`, `slow_mode_seconds`, `locked_at`, `locked_by`. Per subject.
- `rr_read_markers`: `user_id`, `commentable_type`/`_id`, `last_read_at`, unique. Voor "nieuw sinds je laatste bezoek".
- `rr_polls`, `rr_poll_options`, `rr_poll_votes`: mini-peiling aan een reactie (2–4 opties). De stem heeft dezelfde velden als `rr_votes`, unique (poll, voter_key).
- `rr_review_invitations`: `user_id`, `reviewable_type`/`_id`, `source`, `due_at`, `sent_at`, `completed_at`, `dismissed_at`, unique (user, reviewable).
- `rr_uploads`: `user_id`, `disk`, `path`, `alt_text` (nullable, voor later), `attached_type`/`_id`, `attached_at`. Wordt opgeruimd door `rr:prune-uploads`.
- `rr_stickers`: `name` (json), `path`, `is_active`, `sort_order`.

## Services

- `HtmlSanitizer`: `symfony/html-sanitizer` met een allowlist per sectie, opgebouwd uit de ingeschakelde editor-tools.
  - `img` alleen voor eigen uploads of actieve stickers.
  - Links krijgen `rel="nofollow ugc noopener noreferrer"`.
  - Toegestaan: `<a data-rr-card="type:id">` voor productkaarten en `<blockquote data-rr-quote="id">` voor citaten.
  - Begrenzing van het aantal bytes en het aantal afbeeldingen.
- `ReviewService`: indienen, bewerken door de auteur, verwijderen door de auteur, moderatie, en het hertellen van aggregaten via een job (unique per subject, afterCommit).
- `RatingAggregator`:
  - gemiddelde genormaliseerd naar de schaal van de weergave;
  - Bayesiaans gemiddelde, met als prior het mediane aantal reviews per scope (gecachet);
  - histogram;
  - per onderwerp;
  - **onzekerheidsband**: 95%-interval op basis van de standaardfout, gerapporteerd in schaal-eenheden;
  - **scoreverloop**: gemiddelde per maand.
- `DiscussionService`: plaatsen, bewerken (met revisie en opnieuw een spamcheck), verwijderen door de auteur (placeholder als er antwoorden zijn, de antwoorden blijven staan), moderatie (subtree), slow mode, vergrendelen, vertrouwensniveaus, citaten en het venster om ongedaan te maken.
- `DiscussionTree`: een vast aantal queries, roots gepagineerd, replies via `root_id`.
  - Sorteringen: nuttigst (Wilson-ondergrens), nieuwst, oudst, **controversieel**.
  - `controversy_score = (agree + disagree) ^ (min/max)`, 0 bij geen stemmen.
- `VoteService`: één stemtabel. Toggle en wisselen binnen een dimensie, nooit op je eigen bijdrage, `RiskSignals` bepaalt `counted`, tellers worden hersteld uit de rijen.
- `ReportService`: melden, afhandelen (met wie en wanneer), takedown via `Moderatable`.
- `SpamGuard` (deterministisch): honeypot, tijdval, aantal links, hoofdletters, herhaalde tekens, duplicaat in 10 minuten over de **volledige** tekst. De woordenlijst staat in de config.
- `ToneNudge` (deterministisch, nl/en woordenlijst in de config): geeft `null` of een suggestie. Plaatsen met `confirm_tone=true` gaat altijd door.
- `ReviewQualityMeter` (deterministisch): punten voor lengte, plus- en minpunten, gebruik of context, onderwerpscores en concreetheid (cijfers of eenheden). Geeft score 0–100 en hints. Dezelfde regels zitten als JSON in de API, zodat de JS-meter identiek rekent.
- `DiscussionDigest` (TL;DR zonder AI):
  - pas vanaf de drempel uit de instellingen;
  - de N meest gesteunde reacties per standpunt: hoogste eens-score en hoogste oneens-score;
  - de meest nuttige reactie;
  - totalen en de eens/oneens-verhouding;
  - per reactie de eerste zin als uittreksel.
- `SearchService`: FULLTEXT (boolean mode) over reviews en reacties binnen één subject, met gemarkeerde fragmenten (`<mark>` op de gesaneerde platte tekst).
- `ReviewInvitations`: `schedule(user, reviewable, source, delayDays)`, het command `rr:send-review-invitations` (hourly) vuurt het event `ReviewInvitationDue`; de host verstuurt. Een uitnodiging vervalt zodra de review er is.
- `ReadMarkers`: `touch()` bij lezen, `newSince()` voor markeringen en "spring naar eerste nieuwe".

## Events

`ReviewSubmitted`, `ReviewPublished`, `ReviewModerated`, `CommentPosted` (pas na het venster om ongedaan te maken), `CommentEdited`, `CommentModerated`, `VoteCast`, `ContentReported`, `ReportResolved`, `ReviewInvitationDue`, `WantToTryRequested`. Punten, notificaties en volgers worden in de host gekoppeld.

## HTTP-API

Prefix, middleware en throttles komen uit de config. Alle schrijfroutes hebben een throttle.

- `GET  subjects/{type}/{id}/reviews`: lijst. Sortering op nuttig, nieuwst, hoogst of laagst; filters op score, facet en foto's; paginering.
- `GET  subjects/{type}/{id}/rating-summary`: samenvatting met onzekerheid, histogram, onderwerpen en verloop.
- `POST subjects/{type}/{id}/reviews`, `PATCH reviews/{review}`, `DELETE reviews/{review}`
- `POST reviews/{review}/votes`
- `GET  subjects/{type}/{id}/comments`: boom met sortering, cursor en "nieuw sinds".
- `GET  subjects/{type}/{id}/digest`: de TL;DR.
- `GET  subjects/{type}/{id}/search?q=`
- `POST subjects/{type}/{id}/comments`, `PATCH comments/{comment}`, `DELETE comments/{comment}`, `POST comments/{comment}/undo`
- `GET  comments/{comment}/revisions`
- `POST comments/{comment}/votes`, `POST polls/{poll}/votes`
- `POST reports`
- `GET  authors/{id}/card`: hovercard.
- `GET  cards/{type}/{id}`: productkaart.
- `POST subjects/{type}/{id}/want-to-try`
- `POST tone-check`, `GET review-quality-rules`
- Admin, met ability-check: `GET|PATCH admin/sections/{section}`, CRUD `admin/topics`, `admin/reports`, `admin/moderation`.

`{type}` is een morph-alias uit de morph map van de host; onbekende aliassen geven een 404.

## Frontend

- **Gedeeld (`resources/js/`):** de Tiptap-extensieset (StarterKit, Link, Image beperkt tot eigen uploads, Underline, Sticker, ProductCard, QuoteReply), de configuratie per sectie uit de API, en de regels van de kwaliteitsmeter en de herformuleer-suggestie. Plain ES-modules, ook bruikbaar zonder Vue.
- **Blade (WelZoLekker):** `<x-rr::rating-summary>`, `<x-rr::reviews>`, `<x-rr::review-form>`, `<x-rr::discussion>`, `<x-rr::editor>`. Ze werken met progressive enhancement: zonder JavaScript is er een textarea en een gewone POST. Styling via CSS custom properties die de host invult.
- **Vue 3 (NutriCompare):** npm-pakket in `packages/vue/` met dezelfde componenten, die de JSON-API aanspreken. Tiptap en DOMPurify zijn peer-dependencies.

## Migratie van de host-apps

Er gebeurt eerst fase A per app (bugs en morph map). Daarna:

1. Pakket installeren via een vcs-repository, zoals `device-id`.
2. Contracts binden in een ServiceProvider van de host (`RiskSignals` en `VoterIdentity` via `device-id`).
3. Data migreren met idempotente commands (`rr:import-wzl`, `rr:import-nc`) die de bestaande tabellen kopiëren naar `rr_`-tabellen, met verificatie van de aantallen voor en na. De bestaande tabellen blijven staan tot de eigenaar besluit ze op te ruimen.
4. Views en pagina's per subject omzetten naar de pakketcomponenten.

## Afwijkingen bij de bouw van versie 1 (2 november 2026)

- **`RiskSignals::forPollVote(PollVoteContext)`** is toegevoegd: een peilingstem is geen `Votable` en past niet in `VoteContext`. Ook een peilingstem wordt nooit geweigerd, alleen niet meegeteld.
- **`rr_comments.counted`** is toegevoegd (migratie `000006`). Een reactie met `counted=false` is zichtbaar, maar telt niet als bijdrage (`ContributionCounter`, vertrouwensniveaus, TL;DR, topreactie). Een bewerking door de auteur beoordeelt dit opnieuw.
- **`rr_rating_aggregates`** kreeg `facet_counts`, `monthly` en `comparisons`, zodat facetten, scoreverloop en "Vergeleken met…" in de job worden berekend en niet bij elke request.
- **Zichtbaar = goedgekeurd én gepubliceerd** (`published_at`). Een reactie in het venster om ongedaan te maken is alleen voor de auteur zichtbaar, net als de eigen reacties die op moderatie wachten (`visibility`: `scheduled` of `pending`).
- **Onzekerheidsband:** het 95%-interval gebruikt Student's t voor n − 1 vrijheidsgraden (vanaf 121: 1,96), zodat de band bij weinig reviews niet te smal is. Onder 2 reviews is er geen marge.
- **TL;DR:** "per standpunt" betekent netto eens (eens − oneens > 0) en netto oneens; een reactie staat nooit in beide lijsten. `digest_threshold = 0` zet de TL;DR uit.
- **Vertrouwensniveau `deep_reply`** weigert niet, maar plaatst diepere antwoorden op niveau 1 (met `in_reply_to_id`), zoals de bestaande platslaan-regel.
- **"Controversieel"** is er alleen voor reacties: stemmen op reviews zijn eenzijdig (alleen "nuttig").
- **Zoeken** heeft een throttle (`rr-lookup`), net als hovercards, productkaarten en de toon-check.
