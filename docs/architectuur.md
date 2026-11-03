# Architectuur van ratings-and-reviews

Dit document is het bouwcontract voor het pakket. Het volgt uit de [inventarisatie](inventarisatie-2026-11.md) en de beslissingen van 2 november 2026. Wijzigingen in dit contract worden hier eerst vastgelegd en pas daarna gebouwd.

## Uitgangspunten

- **Eén Laravel-pakket** `jw-1980/ratings-and-reviews`, namespace `RatingsAndReviews\`, met auto-discovery via `extra.laravel.providers`.
- **Platform:** PHP `^8.3`, Laravel `^12.0 || ^13.0`, `symfony/html-sanitizer` `^7.2 || ^8.0`. Er komen geen andere runtime-dependencies bij.
- **Database:** MySQL 8.4 is het doel en de testomgeving. Er worden geen SQLite-tests geschreven of gerapporteerd als bewijs (NC-standaard, hoofdstuk 7). Er wordt geen Docker gebruikt. Hosts waarvan de eigen tests op SQLite draaien moeten wel kunnen migreren: MySQL-specifieke schema-onderdelen zijn per driver afgeschermd en runtime-SQL loopt via `Support\SqlDialect` (zie de afwijkingen).
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
  Support/                      waarde-objecten, enums en helpers
  RatingsAndReviewsServiceProvider.php
tests/                           Orchestra Testbench + PHPUnit op MySQL 8.4
packages/vue/                    npm-pakket @jw-1980/ratings-and-reviews-vue (NutriCompare)
```

## Contracts (door de host geïmplementeerd)

| Contract | Doel |
|---|---|
| `Reviewable` / `Commentable` | Op het model van de host (product, additief, merk, …). Bepaalt de sectie (`reviewSectionKey()` / `commentSectionKey()`), of het subject open staat, wie het mag zien en een URL en titel. Traits `HasReviews` en `HasComments` leveren de relaties `rrReviews()`, `rrApprovedReviews()`, `rrRatingAggregate()`, `rrComments()` en `rrApprovedComments()`. De `Review` van het pakket is zelf ook `Commentable` (reacties op reviews). |
| `AuthorResolver` | Leest weergavenaam, avatar en profiel-URL van een `user_id`. Voedt ook de **profiel-hovercard**. |
| `AbilityResolver` | Koppelt pakket-abilities (`rr.moderate`, `rr.manage-settings`, `rr.manage-topics`) aan de RBAC van de host. Krijgt altijd het item of subject (Review, Comment, Report, subject, RatingTopic, Sticker, `SectionScope`, `ModerationScope`). |
| `RiskSignals` | Bepaalt of een stem of bijdrage telt (`counted` met `uncounted_reason`) en of een bijdrage moet wachten. Beide apps implementeren dit met `device-id`. Standaard: alles telt. Een stem-verdict kan host-`meta` meegeven die bij de stem wordt opgeslagen. |
| `VoterIdentity` | Levert de `voter_key` voor leden en gasten (`u:<id>` of `g:<hmac>`) per gestemd item, plus `network_hash` en `device_hash`. |
| `OfficialReplyResolver` | Optioneel. Officiële reacties (merk, winkel) per review, gebatcht; `official_reply` op de review. |
| `PriceAtReviewResolver` | Optioneel. Prijs in centen en valuta van een subject op een datum. |
| `CardResolver` | Optioneel. Rendert een **productkaart** in de editor voor `type:id`. Geeft titel, URL, afbeelding, prijs en score terug. |
| `WantToTryList` | Optioneel. Zet een subject op de lijst van de gebruiker ("Wil ik proberen"). |
| `ContributionCounter` | Telt goedgekeurde bijdragen per gebruiker, voor **vertrouwensniveaus**. Standaard: tellen in de pakkettabellen. |
| `BlockList` | Optioneel. Blokkades tussen leden (`isBlocked`, `blockedWith`); geen antwoorden, citaten of stemmen over een blokkade heen. |
| `AttributionResolver` | Optioneel. Identiteiten waarmee een lid mag plaatsen (merk, winkel, keten); `attribution` op reacties en reviews. |
| Subject-interfaces | Optioneel op het host-model: `ParticipationRules`, `KindAwareParticipationRules` (vijfde ronde), `ScopedModeration`, `HasDiscussionOwner`, `HasDiscussionContext`, `SyncsCommentCount`, `HidesBlockedMembers` (zie de vierde ronde). |

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
  - Subject en auteur: `reviewable_type`/`_id`, `user_id`, `section` (de `reviewSectionKey()` van het subject), `status` (pending, approved, rejected, spam), `erasure_withheld_at`.
  - Score en tekst: `rating` plus `scale_max`, `title`, `body_html`, `body_text`, `pros` en `cons` (json).
  - Features: `facets` (json), `price_cents`, `price_currency`, `compared_type`/`_id`, `comparison` (better/worse/same), `quality_score`.
  - Tellers en herkomst: `helpful_count`, `flags_count`, `ip_hash`, `network_hash`, `device_hash`, `counted`, `uncounted_reason` (sinds de vijfde ronde).
  - Tijden: `approved_at`, `edited_at`, soft deletes.
  - Unique (reviewable, user).
  - **Een afgewezen of als spam gemarkeerde review kan niet automatisch terug naar approved.** Opnieuw indienen geeft weer `pending`.
- `rr_review_topic_ratings`: `review_id`, `topic_id`, `value`, `scale_max`, unique (review, topic).
- `rr_comments`:
  - Plaats in de draad: `commentable_type`/`_id`, `section`, `parent_id`, `root_id`, `depth`, `user_id` (null op een placeholder na wissen), `status`, `erasure_withheld_at`.
  - Inhoud: `body_html`, `body_text`, `quoted_comment_id`, `quote_text`.
  - Tellers: `agree_count`, `disagree_count`, `helpful_count`, `unhelpful_count`, `needs_source_count`, `helpful_score` (stored generated), `controversy_score` (decimal, bij elke hertelling bijgewerkt), `flags_count`.
  - Status en tijden: `is_edited`, `edited_at`, `author_removed_at`, `held_as_evidence`, `publish_at` (venster om ongedaan te maken), `published_at`.
  - Herkomst: `ip_hash`, `network_hash`, `device_hash`, soft deletes.
  - Indexen op (commentable, status, parent_id, helpful_score) en een FULLTEXT op `body_text`.
- `rr_comment_revisions`: `comment_id`, `revision`, `body_html`, `body_text`, `edited_by`, `edited_at`.
- `rr_votes`: `votable_type`/`_id`, `dimension` (agreement, helpfulness, needs_source), `value` (−1, +1), `user_id` (nullable), `voter_key`, `network_hash`, `device_hash`, `counted`, `uncounted_reason`, `meta` (json, van de host), timestamps. Unique (votable, dimension, voter_key). Een stem wordt nooit geweigerd, alleen niet meegeteld.
- `rr_topic_ratings`: `reviewable_type`/`_id`, `user_id`, `topic_id`, `value`, `scale_max`, `counted`, `uncounted_reason`, hashes, `erasure_withheld_at`, unique (subject, user, topic). Onderwerpscores zonder review.
- `rr_reports`: `reportable_type`/`_id`, `section`, `reporter_id` (nullable), `reason` (uit config), `details`, `snapshot` (json), `status` (open, resolved, dismissed), `handled_by`, `handled_at`, `resolution_note`. Eén open melding per melder en item.
- `rr_discussion_states`: `commentable_type`/`_id`, `slow_mode_seconds`, `locked_at`, `locked_by`. Per subject.
- `rr_read_markers`: `user_id`, `commentable_type`/`_id`, `last_read_at`, unique. Voor "nieuw sinds je laatste bezoek".
- `rr_polls`, `rr_poll_options`, `rr_poll_votes`: mini-peiling aan een reactie (2–4 opties). De stem heeft dezelfde velden als `rr_votes`, unique (poll, voter_key).
- `rr_review_invitations`: `user_id`, `reviewable_type`/`_id`, `source`, `due_at`, `sent_at`, `completed_at`, `dismissed_at`, unique (user, reviewable).
- `rr_uploads`: `user_id`, `disk`, `path`, `mime_type`, `size`, `width`, `height`, `alt_text` (nullable), `attached_type`/`_id`, `attached_at`, `role` (`inline` of `photo`), `position`. Wordt opgeruimd door `rr:prune-uploads`.
- `rr_stickers`: `name` (json), `path`, `disk` (gezet als het pakket het bestand opsloeg), `is_active`, `sort_order`.
- `rr_review_questions`: `scope`, `category_key` (nullable), `key`, `type` (scale, choice, multi), `label`/`low_label`/`high_label` (json nl/en), `options` (json), `is_required`, `is_active`, `sort_order`; unique (scope, category_key, key), voor een null-categorie in de service bewaakt.
- `rr_review_answers`: `review_id`, `question_id`, `option_key` ('' bij een schaal), `value` (bij een schaal); unique (review, question, option_key).
- `rr_review_follow_ups`: `review_id` (uniek), `user_id`, `rating`, `scale_max`, `initial_rating`, `initial_scale_max`, `body_text`.
- Sinds de vierde ronde ook: `rr_comments.attribution`, `pinned_at`, `pinned_by`; `rr_reviews.attribution`, `follow_up_invited_at`; `rr_rating_aggregates.question_counts`, `follow_up_stats`.

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
- `VoteService`: één stemtabel. Toggle en wisselen binnen een dimensie, nooit op je eigen bijdrage, `RiskSignals` bepaalt `counted`, tellers worden hersteld uit de rijen. `recountForUsers()` vraagt de stemmen van een groep leden opnieuw aan `RiskSignals` en hertelt (vijfde ronde).
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
- `GET|PUT subjects/{type}/{id}/topic-ratings`: onderwerpscores zonder review.
- `POST subjects/{type}/{id}/reviews`, `PATCH reviews/{review}`, `DELETE reviews/{review}`
- `POST reviews/{review}/votes`
- `GET  subjects/{type}/{id}/comments`: boom met sortering, cursor en "nieuw sinds".
- `GET  subjects/{type}/{id}/digest`: de TL;DR.
- `GET  subjects/{type}/{id}/search?q=`
- `POST subjects/{type}/{id}/comments`, `PATCH comments/{comment}`, `DELETE comments/{comment}`, `POST comments/{comment}/undo`
- `GET  comments/{comment}/revisions`, `GET comments/{comment}/replies` (rest van een afgekapte draad)
- `POST comments/{comment}/votes`, `POST polls/{poll}/votes`
- `POST reports`
- `GET  authors/{id}/card`: hovercard.
- `GET  cards/{type}/{id}`: productkaart.
- `POST subjects/{type}/{id}/want-to-try`
- `POST tone-check`, `GET review-quality-rules`
- `POST uploads`, `GET media/{path}` (private uploads), `GET stickers`
- Admin, met ability-check: `GET|PATCH admin/sections/{section}`, CRUD `admin/topics`, CRUD `admin/stickers` (`rr.manage-settings`), `admin/reports`, `admin/moderation`.
- Opt-in formulierroutes zonder JavaScript (`routes/web.php`, `web_routes.enabled`, middleware `web`): dezelfde schrijfacties als hierboven, met een redirect terug.

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
- **Uploads (2 november 2026, tweede ronde).** Het pakket heeft nu een eigen uploadroute (`POST uploads`) in plaats van alleen een host-callback; `uploadImage` van de host wint nog steeds. Bestanden staan op een publieke disk (host serveert ze onder `uploads.url_prefix`) of op een private disk, waarna het pakket ze serveert onder `/{prefix}/media/{path}`: aan de uploader en moderators altijd, aan anderen alleen zolang de bijdrage publiek is. Daarvoor kwam `illuminate/filesystem` in `composer.json` (onderdeel van Laravel zelf, geen nieuwe externe dependency). JPEG, PNG en WebP worden met GD opnieuw gecodeerd om EXIF (locatie) te verwijderen als GD er is; zonder GD worden ze ongewijzigd bewaard. SVG wordt niet geaccepteerd.
- **Een upload hoort bij één bijdrage.** `MediaSources` laat alleen eigen uploads toe die nog nergens aan hangen of al aan de bijdrage die bewerkt wordt. Zonder die regel kon het verwijderen van de ene reactie de afbeelding van een andere breken. Reviews koppelen hun afbeeldingen nu ook (dat ontbrak); bij het bewerken van een review gaan niet meer gebruikte afbeeldingen en foto's terug naar "vrij" (reviews hebben geen revisies; reacties houden ze, omdat hun revisies ze nog tonen).
- **Opruimen.** Ongedaan maken (undo) maakt uploads vrij in plaats van ze te verwijderen, omdat de tekst terug in de editor komt en opnieuw geplaatst kan worden; `rr:prune-uploads` (elk uur ingepland) verwijdert vrije uploads na `uploads.prune_after_hours`. Verwijdert de auteur een review of reactie, dan gaan de bestanden direct weg, behalve als een open melding de bijdrage als bewijs vasthoudt. Verwijderen door een moderator is een soft delete en houdt de bestanden. Een bestand dat niet verwijderd kan worden houdt zijn rij, zodat een volgende run het opnieuw probeert.
- **Reviewfoto's** zijn `rr_uploads` met `role = photo` (tot `reviews.max_photos`). Een foto heeft geen eigen moderatiestatus: hij volgt de review (niet goedgekeurd: alleen de auteur ziet hem; moderators zien hem in `admin/moderation`). Met `reviews.photos_require_approval` wacht een review die een nieuwe foto krijgt op een moderator, omdat de SpamGuard geen afbeeldingen beoordeelt. Het filter "met foto's" is in de derde ronde gebouwd (`with_photos=1`).
- **Uitgebreide reviewvelden.** Zonder `review_extended_fields` worden titel, plus- en minpunten geweigerd (422) in plaats van stil weggegooid. De review-meta geeft `review_scale`, `review_extended_fields`, `review_min_length` en `review_form` (limieten).
- **Afgekapte draden.** `GET comments/{comment}/replies` geeft de antwoorden plat in boomvolgorde (eerst ondiep, dan oudst) met `cursor`, of met `after` (het laatste antwoord dat de client al toont) voor de eerste aanvraag; antwoorden onder een verborgen reactie blijven weg. Blade laadt ze op de server (`?rr_cthread`), ook zonder JavaScript.
- **Stickers.** Verwijderen haalt de rij weg maar laat het bestand staan (oude berichten tonen het nog), tenzij `delete_file=1` voor een bestand dat het pakket zelf opsloeg. Het plaatje van een bestaande sticker wordt niet vervangen; voeg een nieuwe toe.
- **Posten zonder JavaScript** is niet langer alleen een hostverantwoordelijkheid: `web_routes.enabled` zet pakketroutes aan die de API-controllers in-process aanroepen en terugsturen met `rr_status`, fouten per formulier (`rr_review`, `rr_comment`, `rr_report`, `rr_vote`) en `rr_form`. Bewerken zonder JavaScript toont de opgeslagen HTML in een textarea (de server saneert opnieuw).

## Afwijkingen en aanvullingen op verzoek van de hosts (2 november 2026, derde ronde)

Breaking changes waren toegestaan omdat v0.1 nog niet is uitgebracht. Het overzicht per wijziging staat in de migratienotitie van deze ronde; de beslissingen:

- **SQLite in host-tests.** De FULLTEXT-indexen (000002, 000003) en de gegenereerde `helpful_score` worden alleen op MySQL/MariaDB gemaakt; elders is `helpful_score` een gewone kolom die `Comment` bij opslaan en `VoteService` bij elke hertelling vult. `Support\SqlDialect` levert `GREATEST`/`MAX`, de JSON-sleutel van facetten en jaar-maand per driver; zoeken valt zonder FULLTEXT terug op `LIKE` per term (zonder relevantie), leesmarkeringen op een `ON CONFLICT`-upsert. Het pakket test alleen op MySQL 8.4; dat de basisflows op SQLite draaien is buiten de pakketsuite nagegaan en is geen bewijs van correctheid.
- **Relaties met voorvoegsel.** `HasReviews`/`HasComments` heten nu `rr…()`; het pakket gebruikt ze nergens zelf (het vraagt rr_-tabellen op met morph-type en id). `commentSection()`/`reviewSection()` heten `commentSectionKey()`/`reviewSectionKey()`, omdat beide hosts al een `commentSection(): enum` hebben.
- **Abilities altijd met subject.** Een lijst kan niet per rij gevraagd worden (paginering); daarvoor is `Support\ModerationScope` er (eerst `inbox`, dan per sectie en, bij meldingen, per itemtype). Om op sectie te kunnen filteren kregen `rr_reviews` en `rr_reports` een kolom `section` (000008, met backfill uit de subjects; een subject dat niet laadt houdt null en wordt bij de volgende bewerking gevuld). Een review in een sectie die niet meer bestaat valt onder `ModerationScope` met `section` null. Form requests lossen hun item vóór de validatie op (`PackageRequest::abilitySubject()`), zodat een onbekend item 404 blijft en een verboden item 403 vóór 422.
- **Stemmen.** Geen hostkolommen voor hashes, maar één `meta` (json) op `rr_votes` en `rr_poll_votes` (000009), gevuld uit `RiskVerdict::$meta` in dezelfde transactie; het pakket leest het nooit. Bij wisselen van stem blijft de oorspronkelijke `meta`. `VoterIdentity::voterKey()` krijgt het item; bij lezen vraagt `Support\VoterKeys` de sleutel één keer per getoond item (een pagina met 25 reviews kost 25 aanroepen).
- **Losse onderwerpscores.** Een eigen tabel `rr_topic_ratings` (000011) in plaats van een nullable `review_id`, zodat de review-gebonden scores en hun unique (review, topic) ongewijzigd blijven. Telt mee in de onderwerpgemiddelden, behalve als dezelfde gebruiker hetzelfde onderwerp in een getelde review van het subject scoorde (dan telt de review). `RiskSignals::forContribution()` wordt gevraagd met `kind = topic_rating` en lege tekst; omdat er geen moderatiewachtrij voor scores is, telt een score met `hold` niet mee (reden opgeslagen). Aan/uit per sectie (`standalone_topic_ratings`, standaard uit); `TopicRatings::import()` voor host-importers werkt ongeacht die instelling.
- **Melder nullable.** `rr_reports.reporter_id` is nullable (000004 voor nieuwe installaties, 000010 voor bestaande); twee open meldingen zonder melder botsen niet in de unique index.
- **Wissen.** `erasure_withheld_at` op `rr_reviews`, `rr_comments` en `rr_topic_ratings`, `rr_comments.user_id` nullable (000012). Voor reviews en onderwerpscores een globale scope (`ExcludeErasureWithheld`), omdat ze geen boomstructuur hebben en een vergeten query zo niets lekt; voor reacties expliciet (`withContent()`, `withoutWithheldLeaves()`), omdat een verborgen reactie met antwoorden als placeholder moet blijven staan. Stemmen worden bij `withhold()` niet aangeraakt (geen inhoud; pas `purge()` verwijdert ze). Inhoud die als bewijs wordt vastgehouden (open melding of `held_as_evidence`) blijft bij `purge()` staan, verborgen, soft-deleted en zonder hashes, met `user_id` zodat een moderator hem kan beoordelen; na afhandeling van de melding kan `purge()` opnieuw. De tekst van een melding (`details`) blijft staan; alleen de melder wordt losgekoppeld. Reacties op een gewiste review worden met de review verwijderd (ze hebben geen subject meer), behalve een reactie die als bewijs wordt vastgehouden: dan blijft de review als bewijscontainer staan.
- **Reacties op reviews.** Een review is `Commentable` met de sectie `reviews.replies.section` (standaard `review_replies`, één niveau, alleen "nuttig"). Standaard uit. Zichtbaar met een publieke review van een zichtbaar subject, open zolang het subject reviews aanneemt. In Blade staat de draad onder de lijst (niet genest), zodat de JavaScript-verrijking van lijst en discussie elkaar niet raakt.
- **Oude afbeeldingen.** `Uploads::registerExisting()` codeert niet opnieuw (geen EXIF-strip: het bestand was al publiek) en weigert SVG; met `copy: true` krijgt de kopie een naam afgeleid van de bron, zodat opnieuw importeren dezelfde rij vindt.
- **Officiële reacties.** Een optioneel contract in plaats van een eigen tabel: de host is eigenaar van merkreacties. De tekst is platte tekst die het pakket escapet (`body_html`), zodat er geen host-HTML gesaneerd hoeft te worden. Een falende resolver wordt gerapporteerd en toont geen reacties. Het filter "met foto's" (`with_photos=1`) uit de lijst hierboven is nu gebouwd.

## Reviewronde (3 november 2026)

Deze ronde corrigeert bevindingen uit de review vóór de merge naar `main`. Geen wijziging aan de contracten; wel gedrag dat hosts kunnen merken:

- **Meldingen per sectie.** `PATCH admin/reports/{report}` controleert naast de melding ook `ModerationScope::section(...)`, net als de lijst. Een moderator die een sectie niet mag modereren kan een melding eruit niet meer afhandelen. Het afhandelen, de takedown en het hertellen van `flags_count` zitten in één transactie, zodat een mislukte takedown de melding niet als afgehandeld achterlaat.
- **Wissen en bewijs in een reviewdraad.** Houdt een reactie op een review een open melding of `held_as_evidence` vast, dan blijft de review als bewijscontainer bewaard (verborgen, soft-deleted, zonder hashes) in plaats van dat de reactie met de review verdwijnt.
- **Bewerken van een goedgekeurde review.** `review_requires_approval` geldt bij het indienen, niet bij elke bewerking: een goedgekeurde review blijft goedgekeurd na een schone bewerking. Een nieuwe foto die goedkeuring vereist of een hold/risk-verdict zet hem nog steeds op `pending`.
- **Uitnodigingen.** Een uitnodiging waarvan het subject nu geen reviews aanneemt schuift `invitations.waiting_retry_minutes` (standaard 60) vooruit, zodat een lange rij gesloten subjects latere uitnodigingen niet blokkeert.
- **Host-URL's.** `Support\SafeUrl` staat alleen http(s) en relatieve URL's toe in `AuthorProfile`, `OfficialReply` en `ProductCard`; een `javascript:`- of protocol-relatieve URL wordt null. Het formulier zonder JavaScript redirect naar de sessie-URL in plaats van de client-`Referer`.
- **Indexen (000013).** `rr_review_topic_ratings.topic_id`, `rr_topic_ratings.topic_id`, de nieuwste-reviewvolgorde en de publicatiequery van reacties krijgen een index. `RatingTopicCatalog::update()` herberekent alleen nog bij schaal, actief-status of overerving, niet bij een naam- of volgordewijziging.
- **Aggregatenjob.** `RefreshRatingAggregates` blijft unique tot de start (`ShouldBeUniqueUntilProcessing`), zodat een review die tijdens de run binnenkomt een volgende job plant in plaats van verloren te gaan; de job herhaalt bij een gelijktijdig unique-conflict.
- **Onderwerpscores.** `TopicRatings::rate()` lockt de bestaande rij en herhaalt één keer bij een unique-conflict, zodat twee eerste scores voor dezelfde gebruiker en topic geen 500 geven.
- **Frontend.** De Vue-quote-reply geeft de selectie nu door aan de reply-composer (niet aan de hoofdcomposer), gasten stemmen niet optimistisch in een peiling, de undo-toast krijgt focus, de dialog heeft `aria-describedby`, en de client-sanitizer weigert protocol-relatieve en buiten-de-prefix paden. Het npm-pakket bouwt `dist/` bij `npm pack` (`prepack`) en is publiek publiceerbaar.

## Vierde ronde: de oude review- en reactiesystemen vervangen (3 november 2026)

Besluit van de eigenaar (3 november 2026): de oude review- en reactiesystemen van WelZoLekker en NutriCompare verdwijnen (ze staan nog niet in productie). Het forum van NutriCompare blijft en wordt niet gemigreerd; de reacties op posts wel. Het pakket dekt daarom wat die systemen deden. Breaking changes waren toegestaan (v0.1 is niet uitgebracht); alle wijzigingen zijn uitbreidingen, behalve de constructors van een paar services (alleen relevant voor wie ze zelf bouwt) en `CommentPosted`, dat een tweede argument `recipients` kreeg (standaard `[]`).

### Beslissingen en afwijkingen

- **Optionele interfaces in plaats van verplichte methoden.** `Commentable` en `Reviewable` bleven ongewijzigd, zodat bestaande subjects blijven werken. De haken staan in kleine optionele interfaces (`ParticipationRules`, `ScopedModeration`, `HasDiscussionOwner`, `HasDiscussionContext`, `SyncsCommentCount`, `HidesBlockedMembers`) die `Services\Participation` op één plek leest. Dezelfde antwoorden gelden voor reacties, reviews, vervolgen en onderwerpscores van het subject. De package-`Review` (subject van reacties op reviews) geeft ze door aan zijn eigen subject; zijn auteur is eigenaar van de draad en de pagina van het subject de context.
- **Deelname.** `canParticipate()` geldt voor nieuwe bijdragen (reactie, antwoord, review, vervolg, onderwerpscore), niet voor het bewerken van een bestaande, zoals bij WelZoLekker. `holdsContributionFrom()` false slaat de SpamGuard-hold, een RiskSignals-hold, `review_requires_approval` en `photos_require_approval` over; een harde weigering (honeypot, geblokkeerde zin, duplicaat) en `counted` blijven. Bij onderwerpscores (zonder moderatiewachtrij) betekent een gedwongen hold "telt niet mee" (`uncounted_reason` `held_by_subject`).
- **Moderators per subject** worden met OF bij `rr.moderate` opgeteld (`Participation::mayModerate()`, ook in `requireAbility()` van de form requests). Ze gelden voor items van hun subject; de lijsten `admin/moderation` en `admin/reports` blijven bij de `AbilityResolver`, omdat een lijst niet per rij gevraagd kan worden (zelfde reden als `ModerationScope`).
- **Notificaties** blijven bij de host. `CommentPosted::$recipients` noemt wie bericht moet krijgen (de schrijver van de reactie waarop geantwoord wordt, anders de eigenaar), zonder de auteur zelf en zonder iemand met een blokkade, zoals WelZoLekker het deed.
- **Teller van de host.** De bestaande events dekken posten, publiceren na het undo-venster, modereren, terugsturen na een bewerking en verwijderen; wissen (withhold, restore, purge) had geen event per subject. `SyncsCommentCount` wordt daarom door een pakketlistener op die events en direct door `Erasure` aangeroepen, na de commit, met het aantal gepubliceerde reacties met inhoud. Een fout in de teller van de host wordt gerapporteerd en draait niets terug.
- **Blokkades** komen uit één contract met `blockedWith()` (één aanroep per request, gecachet in de scoped `Participation`), zodat verbergen in de query kan (`Comment::withoutAuthors()`, ook placeholders zonder auteur blijven). Verborgen reacties nemen hun antwoorden mee, zoals in WelZoLekker; `total`, zoeken en de reviewlijst volgen. Niet gefilterd: de TL;DR (`digest`, voor iedereen gelijk) en het aantal "nieuw sinds je laatste bezoek".
- **Attributie** is een momentopname `{key, label, url}` op de bijdrage: de badge blijft staan als het lid het team verlaat (zoals `organization_name` bij WelZoLekker). Alleen sleutels uit `allowedFor()` worden aanvaard; zonder resolver is elke `attribution` 422. Een review houdt zijn attributie bij een bewerking zonder het veld; `null` wist hem. Een reactie houdt hem altijd.
- **Vastzetten.** Eén vastgezette reactie per discussie, alleen op het hoogste niveau en zichtbaar; de vorige verliest de pin in dezelfde transactie. Vastzetten mag een moderator (ook scoped) of de eigenaar. Een "officieel antwoord" is een vastgezette reactie met attributie. Mede-ondertekening door een tweede account (WelZoLekker `OfficialReplyCosign`) zit niet in het pakket.
- **`reviews_enabled`** vervangt de WelZoLekker-middleware `RefuseRatingsReviewsOnRatingOnlySubjects`. Uit: indienen en bewerken geven 422 (`reviews_disabled`), de lijst en het zoeken in reviews zijn leeg, uitnodigingen wachten, de Blade-componenten renderen niets en Vue toont een melding; losse onderwerpscores werken gewoon (zolang het subject `acceptsReviews()`).
- **`min_reviews_for_average`** (sectie, standaard 0) maakt het gemiddelde, het Bayesiaanse gemiddelde, de onderwerpgemiddelden en de gemiddelden van schaalvragen null onder dat aantal (WelZoLekker `BranchReview::MIN_REVIEWS_FOR_AVERAGE`). Filiaalreviews met vier aspecten zijn onderwerpscores op een review of zonder review.
- **Reviewvragen** zijn generiek: smaakdimensies, gebruiksmomenten en extra vragen van WelZoLekker worden allemaal schaal- of keuzevragen. Overerving per sleutel langs `ratingCategoryPath()` (zoals onderwerpen): een vraag met dezelfde sleutel in een specifiekere categorie vervangt de geërfde op zijn plek, een inactieve schakelt hem daar uit. WelZoLekkers `inherits_parent = false` wordt bij import een inactieve overschrijving per geërfde sleutel. Antwoorden zijn optioneel tenzij `is_required`; zonder `answers` bij een bewerking blijven ze staan. De telling per vraag zit in de aggregatenjob (`question_counts`), de labels komen bij het lezen uit de actuele vragen. Beheer met `rr.manage-topics` (geen nieuwe ability: het is dezelfde catalogus en hosts hoeven geen gate toe te voegen).
- **Vervolgen** ("na een maand") horen bij de review: openbaar zolang de review het is, gemeld, gemodereerd en gewist met de review (een melding van de review neemt het vervolg mee in de snapshot; purge verwijdert het via de foreign key). Een vervolgtekst die SpamGuard of RiskSignals vasthoudt zet de hele review terug op `pending`, zoals een vastgehouden bewerking. Het vervolg slaat de score van de review op dat moment op, zodat latere bewerkingen de "gemiddelde verandering" niet verschuiven; die verschijnt vanaf `reviews.follow_ups.min_for_change` (3). Een verwijderde en opnieuw ingediende review verliest zijn vervolg. De uitnodiging is een event (`ReviewFollowUpDue`), één keer per review via `follow_up_invited_at`, in `rr:send-review-invitations`.
- **Review-Q&A** (WelZoLekker `ReviewQuestionService`) is gedekt door reacties op reviews (`reviews.replies`); een blokkade met de auteur van de review weigert een vraag of antwoord.
- **Niet in het pakket** (host blijft eigenaar of het valt buiten de vervanging): punten en achievements, de activiteitenfeed, notificatiekanalen, mede-ondertekening van officiële antwoorden, WelZoLekkers reviewer-biasmodel en -kalibratie, de radargrafiek (de data staat in `questions`), het forum van NutriCompare.

### Migratienotitie voor de hosts

**Nieuwe contracts** (`RatingsAndReviews\Contracts`; standaard uit):

- `BlockList`: `isEnabled(): bool`, `isBlocked(int $userId, int $otherUserId): bool`, `blockedWith(int $userId): list<int>`. Standaard `Defaults\NullBlockList`.
- `AttributionResolver`: `isEnabled(): bool`, `allowedFor(Authenticatable $user, Model $subject): list<Support\Attribution>`. `Support\Attribution(string $key, string $label, ?string $url = null)`. Standaard `Defaults\NullAttributionResolver`.

**Nieuwe optionele subject-interfaces:**

- `ParticipationRules`: `canParticipate(Authenticatable $user): bool`, `holdsContributionFrom(Authenticatable $author): ?bool`.
- `ScopedModeration`: `canModerate(Authenticatable $user): bool`.
- `HasDiscussionOwner`: `discussionOwnerId(): ?int`.
- `HasDiscussionContext`: `discussionContextUrl(): ?string`.
- `SyncsCommentCount`: `syncCommentCount(int $publicComments): void`.
- `HidesBlockedMembers`: `hidesBlockedMembers(): bool`.

**Gewijzigd:** `Events\CommentPosted(Comment $comment, array $recipients = [])`, `recipients` als `list<array{user_id: int, reason: 'reply'|'owner'}>`. `DiscussionService::quote(Model $subject, mixed $quotedId, mixed $quoteText, ?int $authorId = null)`. De constructors van `DiscussionService`, `ReviewService`, `VoteService`, `PollService`, `TopicRatings`, `TrustLevels`, `CommentPublisher`, `DiscussionTree`, `Erasure` en de controllers kregen services erbij (container-resolved). Nieuwe services: `Participation` (scoped), `ReviewQuestions` (scoped), `ReviewFollowUps`, `CommentCounts`.

**Nieuwe events:** `CommentPinned(Comment $comment, bool $pinned, int|string $byUserId, ?int $unpinnedId)`, `ReviewFollowUpDue(Review $review)`, `ReviewFollowUpPosted(ReviewFollowUp $followUp, Review $review, bool $sentBackToModeration)`.

**Migraties** (alleen toevoegend, idempotent, met `down()`):

- `2026_11_03_000014_add_attribution_and_pins`: `rr_comments.attribution` (json), `pinned_at`, `pinned_by`, index `rr_comments_pinned_index`; `rr_reviews.attribution` (json).
- `2026_11_03_000015_create_rr_review_questions_and_follow_ups`: tabellen `rr_review_questions`, `rr_review_answers`, `rr_review_follow_ups`; `rr_reviews.follow_up_invited_at` (index `rr_reviews_follow_up_due_index`); `rr_rating_aggregates.question_counts`, `follow_up_stats`.

Morph-aliassen: `rr_review_question`, `rr_review_answer`, `rr_review_follow_up`.

**Config:** sectie-instellingen `reviews_enabled` (true), `min_reviews_for_average` (0), `review_follow_up_after_days` (0 = uit), `review_follow_up_window_days` (150), ook in `PATCH admin/sections/{section}`; `review_questions.{enabled, scale_max, max_per_subject, max_options, max_label_length}`; `reviews.follow_ups.{max_body_length, min_for_change}`.

**Endpoints:** `POST|DELETE comments/{id}/pin`; `POST|DELETE reviews/{id}/follow-up` (`rating`, `body`, `confirm_tone`); `GET|POST admin/review-questions`, `PATCH|DELETE admin/review-questions/{id}`; formulierroutes `rr.web.comments.pin|unpin`, `rr.web.reviews.follow-up.store|destroy`. Nieuwe invoer: `attribution` bij `POST …/comments` en bij reviews, `answers` bij reviews.

**Responsvelden:** reactie `attribution`, `is_pinned`, `pinned_at`, `is_blocked`, `can.vote`, `can.pin`; reactielijst `meta.pinned_comment_id`, `meta.can_participate`, `meta.can_pin`, `meta.attributions`, `meta.subject {type, id, title, url, context_url}` (`accepts_comments` is false voor wie niet mag deelnemen); review `attribution`, `answers`, `follow_up`, `follow_up_window` (alleen de auteur), `is_blocked`, `can.vote`, `can.follow_up`; reviewlijst `meta.reviews_enabled`, `meta.can_participate`, `meta.attributions`, `meta.review_questions`, `meta.review_follow_up`, `meta.features.follow_ups`, `meta.features.attributions` (`accepts_reviews` volgt `reviews_enabled` en deelname); samenvatting `questions`, `follow_ups`, `min_reviews_for_average`; moderatie-inbox `attribution`, `follow_up`.

**Van WelZoLekkers `Commentable` naar het pakket:** `isOpenForComments` → `acceptsComments`; `isDiscussionVisibleTo` → `commentsVisibleTo`; `canJoinDiscussion` → `ParticipationRules::canParticipate`; `holdsCommentFrom` → `holdsContributionFrom`; `hidesCommentsOfBlockedMembers` → `HidesBlockedMembers::hidesBlockedMembers` (+ `BlockList` over `UserBlock::idsRelatedTo`/`existsBetween`); `canModerateDiscussion` → `ScopedModeration::canModerate`; `discussionOwner` → `HasDiscussionOwner::discussionOwnerId` en `CommentPosted::$recipients`; `syncCommentCount` → `SyncsCommentCount`; `discussionUrl`/`discussionTitle` blijven; `discussionContextUrl` → `HasDiscussionContext`; `brand_account_id`/"hats" en `OfficialReply` → `AttributionResolver` + pin. NutriCompare: productreacties en postreacties worden `Commentable` (zichtbaarheid van posts in `commentsVisibleTo`, vrienden/groepen in `ParticipationRules`, `Messaging\UserBlock` in `BlockList`); merkreacties op reviews via `OfficialReplyResolver` of als reactie op de review met attributie.

## Vijfde ronde (3 november 2026)

Een kleine ronde met één bug en vier aanvullingen. Alles is toevoegend; de enige zichtbare wijziging in bestaand gedrag is dat het stemantwoord velden erbij kreeg en dat de JavaScript-client die nu leest.

### Beslissingen

- **Stemantwoord expliciet (bug).** `VoteService::recount()` geeft de tellers per kolom (`agree_count`, …), en dat ging ongewijzigd als `counts` naar de client, terwijl `normalize.voteResult` `counts.up`/`counts.down` las: na elke stem via JavaScript stond de teller op 0, bij elke dimensie en bij de nuttig-stem op reviews. Het antwoord heeft nu een vaste, gedocumenteerde vorm (`Support\VoteResult::toArray()`): `count` (`{up, down}` van de gestemde dimensie), `counts_by_dimension` (elke dimensie van de sectie, dezelfde vorm als `counts` in de `CommentResource`) en `counts` (de ruwe kolommen, ongewijzigd gelaten voor bestaande clients). `normalize.voteResult` geeft `counts` (de gestemde dimensie, zoals de componenten al verwachtten) en `allCounts`; een antwoord met alleen kolommen wordt nog gelezen. Vue en de Blade-verrijking werken ook de andere dimensies bij. De mocks in de JS-tests hadden de verkeerde vorm, daarom viel het niet op; de nieuwe tests gebruiken het antwoord dat de PHP-test vastlegt. Peilingen hadden het probleem niet (`PollService::present()`).
- **`uncounted_reason` op reviews.** Zoals bij stemmen en onderwerpscores staat de reden nu bij de review. Gezet uit het RiskSignals-verdict (indienen en bewerken) of door de host met `ReviewService::setCounted()` (bijvoorbeeld een proefpakket van het merk, of na een fraudecontrole). Labels en zichtbaarheid komen uit `reviews.uncounted_reasons` (host-data in de config, per taal; zonder label de pakketvertaling `enums.uncounted_reason.<key>` of het algemene label, geen terugvaltaal). Een reden is alleen voor de auteur en moderators, tenzij `public` (dan een label op de kaart voor iedereen: openheid over gekregen producten). `keep_on_edit` houdt een hostbeslissing vast bij een bewerking; zonder die vlag beslist RiskSignals bij elke bewerking opnieuw, zoals voorheen. De resource vraagt `rr.moderate` alleen voor een niet-tellende review met een niet-publieke reden die niet van de kijker is, zodat een lijst niet per rij vraagt. Alleen reviews kregen de kolom: reacties tellen nergens zichtbaar mee.
- **Deelname per soort.** Een nieuwe optionele interface `KindAwareParticipationRules::canParticipateIn($user, $kind)` in plaats van een extra parameter op `ParticipationRules::canParticipate()`: een parameter toevoegen aan een interfacemethode breekt elke bestaande implementatie (PHP eist dezelfde signatuur), een tweede interface niet. Heeft een subject hem, dan beslist hij voor elke soort; `holdsContributionFrom()` blijft bij `ParticipationRules`. De package-`Review` geeft een reactie op de review door als `review_reply` aan het gereviewde subject, zodat filiaalpersoneel op filiaalreviews kan antwoorden zonder zelf te mogen reviewen.
- **Indexen.** `rr_votes.user_id` had al een index (000004); `rr_poll_votes.user_id` niet. Migratie 000017 voegt de ontbrekende toe (en die op `rr_votes` alleen als een database hem mist).
- **Hertellen voor een groep leden.** `VoteService::recountForUsers($userIds)` vraagt elke stem en peilingstem van die leden opnieuw aan RiskSignals, per item onder de rijlock en oudste eerst; `existingVotes` zijn de stemmen die vóór deze stem werden uitgebracht, zodat de volgorde van de oorspronkelijke beslissingen behouden blijft en niet steeds een andere stem "de eerste" wordt. Een gewijzigd verdict zet `counted` en `uncounted_reason` (en nieuwe `meta`), daarna worden de tellers altijd herteld (ook verlopen tellers worden zo hersteld). Er wordt niets geweigerd of verwijderd en er is geen event: de host roept het zelf aan, voor grote clusters vanuit een job. Bijdragen (reviews, reacties) worden niet opnieuw beoordeeld; daarvoor is `setCounted()` er voor reviews.
- **Niet gedaan.** `accepts_ratings` van `GET …/topic-ratings` kijkt nog niet naar deelname (een PUT geeft wel 403); reacties hebben geen `uncounted_reason`.

### Migratienotitie voor de hosts

**Migraties** (toevoegend, idempotent, met `down()`): `2026_11_03_000016_add_uncounted_reason_to_rr_reviews` (`rr_reviews.uncounted_reason`, string 64, nullable; bestaande niet-tellende reviews houden `counted = false` zonder reden) en `2026_11_03_000017_add_user_indexes_to_rr_votes` (`rr_poll_votes_user_id_index`, en `rr_votes_user_id_index` als die ontbreekt). `php artisan migrate`.

**Stemantwoord** van `POST reviews/{id}/votes` en `POST comments/{id}/votes`: nieuw `count` en `counts_by_dimension`; `counts` (kolommen) blijft. Wie de JSON zelf leest: gebruik `count`. Vue-hosts: `VoteResult` (event `voted` van `RrVoteButtons`) kreeg `allCounts`; `counts` is de gestemde dimensie zoals voorheen bedoeld, nu met de juiste waarden.

**Config:** `reviews.uncounted_reasons` (standaard `sample_campaign` met `nl` "Gekregen van het merk", `en` "Received from the brand", `public` true, `keep_on_edit` true). Een gepubliceerde config moet de sleutel overnemen om de standaard te houden.

**Responsvelden:** review `uncounted_reason`, `uncounted_label`, `uncounted_public` (null/false als hij telt of als de kijker de reden niet mag zien); moderatie-inbox `counted`, `uncounted_reason`, `uncounted_label`.

**Nieuw in PHP:**

- `Contracts\KindAwareParticipationRules`: `canParticipateIn(Authenticatable $user, string $kind): bool`; soorten in `Support\ParticipationKind` (`REVIEW`, `COMMENT`, `REVIEW_REPLY`, `TOPIC_RATING`, `FOLLOW_UP`). Optioneel; zonder interface blijft `ParticipationRules::canParticipate()` voor elke soort gelden.
- `Services\Participation::canParticipate($user, $subject, string $kind = 'comment')` en `assertCanParticipate(…, string $kind = 'comment')`: optionele derde parameter.
- `ReviewService::setCounted(Review $review, bool $counted, ?string $reason = null): Review` (een reden is verplicht bij `false`, anders `InvalidArgumentException`; herberekent de aggregaten).
- `VoteService::recountForUsers(array $userIds): array{votes, votes_changed, items, poll_votes, poll_votes_changed, polls}`, `VoteService::votableByKey(string $type, int $id)`, `PollService::recountForUsers(array $userIds)`.
- `VoteContext` en `PollVoteContext` kregen een optioneel laatste argument `vote` (de opgeslagen rij bij herbeoordeling) en `isReevaluation()`. Een RiskSignals-implementatie die `existingVotes` telt hoeft niets te veranderen.
- `Support\VoteResult` kreeg `countsByDimension` (optioneel constructorargument), `byDimension()`, `count()` en `toArray()`; `Support\UncountedReasons` leest de config.
- De constructor van `VoteService` kreeg `PollService` (container-resolved).

**Vertalingen:** `enums.uncounted_reason.{default, held_by_subject, imported}`, `ui.reviews.{uncounted_public, own_uncounted, uncounted_moderator}`. CSS: `.rr-tag--uncounted`.

