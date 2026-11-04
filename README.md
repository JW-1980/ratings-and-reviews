# ratings-and-reviews

Gedeeld Laravel-pakket voor beoordelingen, sterrenreviews per onderwerp, threaded discussies, stemmen en moderatie, gebruikt door WelZoLekker en NutriCompare.

Het pakket is in opbouw. De inventarisatie en het ontwerp staan in [`docs/`](docs/).

## Installatie

Het pakket is privé en wordt geïnstalleerd via een vcs-repository:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/JW-1980/ratings-and-reviews" }],
"require": { "jw-1980/ratings-and-reviews": "^0.1" }
```

De ServiceProvider wordt automatisch geladen. Daarna:

```bash
php artisan vendor:publish --tag=ratings-and-reviews-config   # optioneel
php artisan migrate                                            # maakt de rr_-tabellen
```

Vereisten: PHP 8.3+, Laravel 12 of 13, MySQL 8.4; `ext-gd` (aanbevolen) om EXIF uit geüploade foto's te halen, zonder GD worden ze ongewijzigd opgeslagen. Een host waarvan de eigen testsuite op SQLite draait kan migreren en de basisflows gebruiken: FULLTEXT-indexen en de gegenereerde Wilson-kolom bestaan alleen op MySQL/MariaDB, zoeken valt daar terug op `LIKE` en de overige MySQL-specifieke SQL loopt via `Support\SqlDialect`. Alleen MySQL 8.4 is getest en ondersteund. Publiceerbare tags: `ratings-and-reviews-config`, `-migrations`, `-translations`, `-views`.

## Gebruik

1. **Subjects.** Implementeer `RatingsAndReviews\Contracts\Reviewable` (met `reviewSectionKey()`) en/of `Commentable` (met `commentSectionKey()`) op een host-model, gebruik de traits `RatingsAndReviews\Concerns\HasReviews` (`rrReviews()`, `rrApprovedReviews()`, `rrRatingAggregate()`) en `HasComments` (`rrComments()`, `rrApprovedComments()`), en registreer het model in de morph map van de host (`Relation::enforceMorphMap(['product' => Product::class])`). De relaties hebben het voorvoegsel `rr` zodat ze niet botsen met eigen `reviews()`/`comments()`; het pakket zelf gebruikt ze niet. De API kent alleen aliassen uit de morph map; elke andere `{type}` geeft 404. Ook een review is een subject voor reacties (`rr_review`, zie `reviews.replies`).
2. **Contracts binden** in een ServiceProvider van de host. Elk contract heeft een veilige standaard:

   | Contract | Standaard |
   |---|---|
   | `AbilityResolver` | vraagt de Gate; abilities `rr.moderate`, `rr.manage-settings`, `rr.manage-topics` (zie `Support\Ability`). Niet gedefinieerd = geweigerd. Het derde argument is altijd waar de vraag over gaat (zie hieronder). |
   | `AuthorResolver` | naam uit het user-model (`authors.model`/`name_attribute`), geen avatar |
   | `RiskSignals` | alles telt, niets wacht (bind een device-id-implementatie). Een verdict voor een stem of peilingstem kan `meta` meegeven (`RiskVerdict::counts()->withMeta([...])`); dat staat dan in `rr_votes.meta` / `rr_poll_votes.meta`. De reden van een niet-tellende review staat in `rr_reviews.uncounted_reason`. Verandert het fraudebeeld (een accountcluster ontstaat of valt uiteen), roep dan `VoteService::recountForUsers($userIds)` aan: alle stemmen en peilingstemmen van die leden worden opnieuw gevraagd (`VoteContext::$vote`/`PollVoteContext::$vote` is dan de opgeslagen rij, `isReevaluation()` true) en de tellers herteld. |
   | `VoterIdentity` | `u:<id>` voor leden, `g:<hmac>` van netwerk + user agent voor gasten. `voterKey($request, $user, $votable)` krijgt het item (Review, Comment of Poll), ook bij het lezen van de eigen stemmen (één keer per item). |
   | `PriceAtReviewResolver`, `CardResolver`, `WantToTryList`, `OfficialReplyResolver` | uitgeschakeld (`isEnabled()` is false) |
   | `ContributionCounter` | telt goedgekeurde reviews en reacties in de rr_-tabellen |
   | `BlockList` | geen blokkades (`isEnabled()` false). `isBlocked(a, b)`, `blockedWith(userId)` (beide richtingen). Afgedwongen: geen antwoord op of citaat van een reactie, geen antwoord op de review en geen stem of peilingstem op een bijdrage van iemand met wie een blokkade bestaat (422 `parent_id`/`quoted_comment_id`/`body`, 403 bij stemmen); niemand in `CommentPosted::$recipients` over een blokkade heen; `is_blocked` op reacties en reviews. |
   | `AttributionResolver` | uit. `allowedFor(user, subject)` → `list<Support\Attribution(key, label, url)>`: plaatsen "als" een merk of winkel met `attribution` (sleutel) bij reacties en reviews; opgeslagen als momentopname `{key, label, url}`, getoond als badge. Een onbekende sleutel (of zonder resolver) geeft 422. |
   | `UploadScanner` | doet niets (`NullUploadScanner`). `scan(UploadedFile\|string $path): Support\ScanResult` (`ScanResult::clean()` of `ScanResult::infected($reden)`): een malwarescanner (ClamAV, een clouddienst). `Uploads::store()` en `registerExisting()` roepen hem aan vóór er iets bewaard wordt; besmet geeft 422 (`image`, bij importeren `path`) met een vertaalde melding, geen bestand, geen rij en het event `Events\UploadRejected`. Een scanner die een exceptie gooit weigert ook (faalt gesloten, `report()`), tenzij `uploads.scanner.fail_open` aan staat. |
   | `ContentAnnotator` | geen. `annotate(list<Review\|Comment> $items, ?Authenticatable $viewer): array<itemKey, list<Support\Annotation(key, label, tone, url)>>`, één keer per lijst; zie "Annotaties op kaarten". |

   **Optionele subject-interfaces** (naast `Commentable`/`Reviewable`, alles optioneel, zonder interface gedraagt het pakket zich als voorheen; de package-`Review` geeft ze door aan zijn eigen subject):

   | Interface | Wat het doet |
   |---|---|
   | `ParticipationRules` | `canParticipate(user)`: wie mag schrijven (reactie, antwoord, review, vervolg, onderwerpscore; anders 403, `meta.can_participate` false). `holdsContributionFrom(author): ?bool`: true = altijd voormoderatie, false = direct publiceren (alleen een harde SpamGuard-weigering weigert nog), null = de gewone checks. |
   | `KindAwareParticipationRules` | `canParticipateIn(user, kind)`: wie mag schrijven, per soort (`Support\ParticipationKind`: `review`, `comment`, `review_reply`, `topic_rating`, `follow_up`). Beslist in plaats van `canParticipate()` als het subject het heeft. Een reactie op een review wordt aan het gereviewde subject gevraagd als `review_reply` (filiaalpersoneel antwoordt op reviews van het filiaal). |
   | `ScopedModeration` | `canModerate(user)`: moderators per subject (filiaal, keten, merk), OF met `rr.moderate`. Geldt voor de item-endpoints (bewerken, verwijderen, vastzetten, `PATCH admin/moderation/{kind}/{id}`, `admin/discussions`, vrijstelling van vertrouwensniveaus en slow mode); de inboxen `admin/moderation` en `admin/reports` blijven bij de `AbilityResolver`. |
   | `HasDiscussionOwner` | `discussionOwnerId()`: de eigenaar mag een reactie vastzetten en staat in `CommentPosted::$recipients` bij nieuwe reacties op het hoogste niveau. Een review is eigenaar van zijn eigen reactiedraad (de auteur). |
   | `HasDiscussionContext` | `discussionContextUrl()`: de pagina van het subject zelf ("terug naar"), `meta.subject.context_url`. |
   | `SyncsCommentCount` | `syncCommentCount(int)`: na elke wijziging van het aantal publieke reacties (na de commit; ook bij wissen). |
   | `HidesBlockedMembers` | `hidesBlockedMembers()`: reacties (met de antwoorden eronder) en reviews van geblokkeerde leden weggelaten voor de kijker, ook uit `total` en zoeken. |

   **Ability-subjects.** `rr.moderate` krijgt de `Review`, `Comment` of `Report` waar het om gaat, het `Commentable`/`Reviewable` subject (discussie vergrendelen of slow mode, vrijstelling van vertrouwensniveaus, plaatsen in een vergrendelde discussie) of, voor de lijsten `admin/moderation` en `admin/reports`, een `Support\ModerationScope`: eerst `inbox` (mag de gebruiker deze lijst openen), daarna per sectie (en bij meldingen per itemtype) die in de lijst voorkomt; de lijst bevat alleen wat mag (`meta.sections`, `meta.scopes`). `rr.manage-settings` krijgt een `Support\SectionScope` of een `Sticker`, `rr.manage-topics` een `RatingTopic` (bij aanmaken een niet-opgeslagen topic met de ingediende scope). Zonder subject (`null`) vraagt het pakket alleen nog bij de ingang van `admin/sections`, `admin/topics` en `admin/stickers`. `rr_reviews.section` en `rr_reports.section` maken het filteren per sectie mogelijk.

   ```php
   $this->app->singleton(\RatingsAndReviews\Contracts\RiskSignals::class, DeviceIdRiskSignals::class);
   Gate::define('rr.moderate', fn (User $user, mixed $subject = null) => $user->hasPermission('moderate'));
   ```
3. **Config** (`config/ratings-and-reviews.php`): `routes` (prefix `rr`, middleware, admin-middleware, guard), `web_routes` (opt-in formulierroutes zonder JavaScript, zie Frontend), `rate_limits` (named limiters `rr-write`, `rr-vote`, `rr-report`, `rr-admin`, `rr-lookup`, `rr-upload`), `section_defaults` en `sections.<naam>` (diepte, editor-tools, stemdimensies, gaststemmen, sortering, lengtes, bewerktermijn, reviewschaal, uitgebreide reviewvelden, minimale reviewlengte, …; een admin overschrijft ze per sectie via `PATCH admin/sections/{section}`), `discussions` (`replies_per_request`), `reviews` (`max_photos`, `photos_require_approval`, lengtes, `replies.enabled`/`replies.section` voor reacties op reviews, standaard uit, sectie `review_replies`), sectie-instelling `standalone_topic_ratings` (onderwerpscores zonder review, standaard uit), `rating_topics.max_active`, `reports.reasons`, `sanitizer`, `uploads` (zie hieronder), `stickers` (`url_prefix`, `disk`, `directory`, limieten) en `spam` (woordenlijsten en drempels), `review_questions` (`enabled`, `scale_max` 5, `max_per_subject`, `max_options`, `max_label_length`) en `reviews.follow_ups` (`max_body_length`, `min_for_change`), `reviews.uncounted_reasons` (reden → `nl`/`en`-label, `public`, `keep_on_edit`; standaard `sample_campaign` "Gekregen van het merk", publiek en blijvend bij bewerken). Sectie-instellingen sinds de vierde ronde: `reviews_enabled` (standaard true; false = geen geschreven reviews, alleen losse onderwerpscores), `min_reviews_for_average` (0; gemiddelden pas vanaf zoveel scores), `review_follow_up_after_days` (0 = uit) en `review_follow_up_window_days` (150).

   **Uploads** (`uploads.*`): `visibility` `public` (bestanden op `disk`, standaard `public`, door de host geserveerd onder `url_prefix`; `php artisan storage:link`) of `private` (niet-publieke disk; het pakket serveert ze onder `/{prefix}/media/{path}`, aan de uploader en moderators altijd, aan anderen alleen zolang de review of reactie waar ze bij horen publiek is; dat werkt met sessie-auth, niet met bearer tokens in `<img>`). Limieten: `max_kilobytes`, `mime_types` (standaard JPEG, PNG, WebP, GIF; geen SVG), `min_/max_width|height`, `daily_quota` per 24 uur (429 met `Retry-After`) plus `rr-upload` per minuut. `strip_metadata` codeert JPEG/PNG/WebP opnieuw met GD (als die geïnstalleerd is), zodat EXIF-locatie niet gepubliceerd wordt; een JPEG wordt eerst rechtop gezet. **GIF**: GD kan een animatie niet herschrijven (het leest en schrijft alleen het eerste beeld), daarom herschrijft `Support\GifSanitizer` een GIF blokgewijs zonder GD: de animatie, framevolgorde en lus blijven, commentaar-, tekst- en toepassingsblokken (XMP en andere metadata, behalve de lusteller) en alle bytes na de trailer verdwijnen, de pixelgegevens blijven ongewijzigd. Een GIF die niet te parsen of afgekapt is wordt geweigerd (422). Wie geen animaties wil zet `uploads.allow_gif` op `false` (standaard `true`): GIF valt dan uit `mime_types` (validatie, `uploadTypes` in de client, `registerExisting`) en `Uploads::store()` weigert hem met een eigen melding. Met `strip_metadata` uit wordt elk bestand ongewijzigd bewaard. `scanner.fail_open` (standaard `false`) bepaalt wat er gebeurt als de `UploadScanner` een fout geeft. `prune_after_hours`: daarna ruimt `rr:prune-uploads` nooit gekoppelde uploads op.
4. **API** onder `/{prefix}`. Alle schrijfroutes hebben een throttle; zoeken, kaarten, hovercards en de toon-check hebben de limiter `rr-lookup`.

   | Route | Feature |
   |---|---|
   | `GET subjects/{type}/{id}/reviews` | lijst (leeg met `reviews_enabled` uit); elke review heeft ook `uncounted_reason`, `uncounted_label`, `uncounted_public` (waarom hij niet meetelt: voor de auteur en moderators, voor iedereen als de reden publiek is; anders null), `attribution`, `answers[{key,label,type,value,display,scale_max}]`, `follow_up` (`{rating, scale_max, initial_rating, initial_scale_max, body, created_at, days_after}` of null), `is_blocked`, `can.vote`, `can.follow_up`, voor de auteur `follow_up_window`; `meta.reviews_enabled`, `meta.can_participate`, `meta.attributions`, `meta.review_questions`, `meta.review_follow_up`, `meta.features.follow_ups`/`attributions`; verder `sort`, `rating`, `facet[groep]=waarde`, `with_photos=1`; elke review heeft `replies_count` (null als reacties op reviews uit staan), `can.reply` en, met een `OfficialReplyResolver`, `official_reply` (`{author: {name, url, avatar_url}, label, body, body_html, replied_at}` of null); `meta` met facetten, editor-vertrouwen (`meta.editor`), `features`, `review_scale`, `review_extended_fields`, `review_min_length` en `review_form` (`max_body_length`, `max_title_length`, `max_pros_cons`, `max_pro_con_length`, `max_photos`, `photos_require_approval`, `requires_approval`); elke review heeft `photos[{id,url,alt,width,height}]` |
   | `POST reviews/{id}/votes`, `POST comments/{id}/votes` | `{dimension, value}` → `data: {dimension, value (null = ingetrokken), counted, count: {up, down}, counts_by_dimension: {dimensie: {up, down}}, counts: {kolom: n}}`: `count` is de gestemde dimensie, `counts_by_dimension` elke dimensie van de sectie (zelfde vorm als `counts` in de reactie), `counts` de ruwe tellerkolommen (`agree_count`, `helpful_count`, …) |
   | `POST|DELETE reviews/{id}/follow-up` | vervolg van de auteur (`rating`, optioneel `body`), binnen het venster van de sectie; een tekst die wordt vastgehouden zet de review terug op `pending` |
   | `POST subjects/{type}/{id}/reviews`, `PATCH`/`DELETE reviews/{id}` | met `answers` (`{sleutel: waarde}`; weglaten bij `PATCH` houdt ze), `attribution` (weglaten bij `PATCH` houdt hem, null wist), `facets`, `compared_type`/`compared_id`/`comparison`, `photos` (upload-id's in volgorde; weglaten bij `PATCH` houdt de huidige), `confirm_tone`; titel/pluspunten/minpunten geven 422 als de sectie geen uitgebreide velden heeft; antwoord bevat `quality` |
   | `GET subjects/{type}/{id}/rating-summary` | plus `uncertainty`, `trend`, `facets`, `comparisons`, `questions` (per vraag `count`, bij een schaal `average` en `distribution`, bij keuzes `options[{value,label,count,share}]`), `follow_ups` (`{enabled, count, min_for_change, average_before, average_after, average_change}`) en `min_reviews_for_average`; de onderwerpgemiddelden tellen losse onderwerpscores mee |
   | `GET|PUT subjects/{type}/{id}/topic-ratings` | onderwerpscores zonder review (sectie `standalone_topic_ratings`): `{enabled, accepts_ratings, topics: [{id, name, scale_max, count, average}], own}`; `PUT {ratings: {topic_id: waarde of null}}` (null wist) |
   | `GET|POST subjects/rr_review/{id}/comments` | reacties op een review (`reviews.replies.enabled`), met alle andere reactie-endpoints |
   | `GET subjects/{type}/{id}/comments` | boom met `visibility` (eigen pending/gepland), `quote`, `poll`, `is_new`, `attribution`, `is_pinned`, `pinned_at`, `is_blocked`, `can.vote`, `can.pin`; de vastgezette reactie staat bovenaan pagina 1; `meta.trust`, `meta.discussion`, `meta.new_since`, `meta.pinned_comment_id`, `meta.can_participate`, `meta.can_pin`, `meta.attributions`, `meta.subject {type, id, title, url, context_url}` |
   | `POST subjects/{type}/{id}/comments` | met `quoted_comment_id` + `quote_text`, `poll`, `confirm_tone`, `attribution` |
   | `POST|DELETE comments/{id}/pin` | één reactie op het hoogste niveau per discussie vastzetten (moderator, ook scoped, of discussie-eigenaar); vastzetten neemt de pin van de vorige |
   | `GET|POST admin/review-questions`, `PATCH|DELETE admin/review-questions/{id}` | reviewvragen per scope en categorie (`rr.manage-topics` met de `ReviewQuestion`, bij aanmaken een niet-opgeslagen vraag met scope): `key`, `type` (`scale`, `choice`, `multi`), `label`/`low_label`/`high_label` en `options[].label` (nl/en), `is_required`, `is_active`, `sort_order`; type, scope, categorie en sleutel liggen vast |
   | `POST comments/{id}/undo` | ongedaan maken binnen `undo_window_seconds` |
   | `GET subjects/{type}/{id}/digest` | TL;DR (extractief, vanaf `digest_threshold`) |
   | `GET subjects/{type}/{id}/search?q=&in=` | FULLTEXT-zoeken met `<mark>`-fragmenten |
   | `POST subjects/{type}/{id}/want-to-try` | "Wil ik proberen" (404 zonder `WantToTryList`) |
   | `GET polls/{id}`, `POST polls/{id}/votes` | mini-peiling |
   | `GET authors/{id}/card`, `GET cards/{type}/{id}` | hovercard, productkaart (404 zonder `CardResolver`) |
   | `POST tone-check`, `GET review-quality-rules` | herformuleer-suggestie, regels van de kwaliteitsmeter (JSON) |
   | `POST uploads` | afbeelding (multipart `image`, optioneel `alt`) → `201 {data: {id, url, alt, width, height}}`; throttle `rr-upload`, dagquotum (429) |
   | `GET media/{path}` | private upload (alleen bij `uploads.visibility = private`; anders 404) |
   | `GET comments/{id}/replies?after=&cursor=&limit=` | de rest van een afgekapte draad (`meta.truncated_threads`): plat, in boomvolgorde, `parent_id` om te plaatsen; `meta.{root_id,total,next_cursor,has_more}` |
   | `GET stickers` | actieve stickers voor de editor `[{id, name, url}]` |
   | `GET|POST admin/stickers`, `PATCH|DELETE admin/stickers/{id}` | stickerbeheer (`rr.manage-settings`): `image` (upload) of `path`, `name.nl`/`name.en`, `is_active`, `sort_order`; verwijderen laat het bestand staan tenzij `delete_file=1` |
   | `GET review-invitations`, `POST review-invitations/{id}/dismiss` | open uitnodigingen van het lid |
   | `GET|PATCH admin/sections/{section}`, `admin/topics`, `admin/reports`, `admin/moderation` | beheer |
   | `GET|PATCH admin/discussions/{type}/{id}` | slow mode en vergrendelen (`rr.moderate`) |

5. **Features van versie 1** (allemaal deterministisch, geen AI):
   - TL;DR van lange discussies, zoeken binnen een subject, prijs op het moment van de review (`PriceAtReviewResolver`), "Vergeleken met…", kwaliteitsmeter (`quality` in de config), review-uitnodigingen (`ReviewInvitations::schedule()`), context-facetten (`reviews.facets`), onzekerheidsband en scoreverloop in de samenvatting, sorteren op "Controversieel" (reacties), citeer-selectie, productkaart, mini-peiling (`polls`), "Nieuw sinds je laatste bezoek", slow mode en vergrendelen, vertrouwensniveaus (`trust_levels` per sectie), herformuleer-suggestie (`tone.words`), profiel-hovercard, ongedaan maken na plaatsen (`undo_window_seconds`), "Wil ik proberen" (`WantToTryList`).
   - **Commands** (automatisch ingepland, uit te zetten met `schedule.enabled`): `rr:publish-due` (elke minuut: reacties na het venster publiceren, daarna pas `CommentPosted`), `rr:send-review-invitations` (elk uur: `ReviewInvitationDue`) en `rr:prune-uploads` (elk uur, `--hours=` overschrijft de wachttijd: nooit gekoppelde uploads met hun bestand verwijderen).
   - **Uploads en foto's**: een upload hoort bij één bijdrage. De sanitizer laat alleen eigen uploads toe die nog nergens aan hangen of al aan de bijdrage die bewerkt wordt; bij opslaan worden afbeeldingen in de tekst (`inline`) en reviewfoto's (`photo`) gekoppeld. Ongedaan maken (undo) maakt de uploads weer vrij (de tekst gaat terug naar de editor); verwijdert de auteur een review of reactie, dan gaan de bestanden direct weg, behalve als een open melding de bijdrage als bewijs vasthoudt. Verwijderen door een moderator houdt ze. Foto's van een review die niet goedgekeurd is ziet alleen de auteur (en moderators in `admin/moderation`); met `reviews.photos_require_approval` wacht een review die een foto krijgt op een moderator.
   - **Wissen (AVG).** `Services\Erasure`: `withhold($userId)` verbergt reviews, reacties en onderwerpscores van iemand met een openstaand wisverzoek overal (`erasure_withheld_at`; een reactie met antwoorden wordt een placeholder), `restore($userId)` maakt dat ongedaan, `purge($userId)` wist definitief: stemmen en peilingstemmen (tellers opnieuw geteld), leesmarkeringen, uitnodigingen, onderwerpscores, reviews (met foto's en reacties op de review) en reacties (een reactie met antwoorden wordt een anonieme placeholder, `user_id` null); inhoud die een melding als bewijs vasthoudt blijft, zonder hashes en verborgen. Meldingen, revisies, vergrendelingen en instellingen worden losgekoppeld (`reporter_id` null). Uploads gaan na de commit weg, aggregaten worden herberekend, `UserErasureProcessed` meldt de aantallen.
   - **Oude afbeeldingen importeren.** `Uploads::registerExisting($userId, $disk, $path, $attachTo, $role, $alt, $position, $copy)` maakt van een al opgeslagen afbeelding een upload van de gebruiker (inline of reviewfoto), zodat geïmporteerde reacties hun plaatjes houden; met `copy: true` naar de uploaddisk van het pakket. Idempotent per pad. Losse onderwerpscores importeren: `TopicRatings::import()`.
   - `RiskSignals` kreeg `forPollVote()`. Een bijdrage met `counted=false` blijft zichtbaar, maar telt niet als bijdrage (vertrouwensniveaus, TL;DR, topreactie); bij reviews telt hij ook niet mee in de aggregaten, met de reden in `uncounted_reason`. De host zet een review zelf op niet-tellend met `ReviewService::setCounted($review, false, 'sample_campaign')` (en terug met `true`); een reden met `keep_on_edit` blijft staan als de auteur bewerkt, een andere wordt bij de volgende bewerking door het RiskSignals-verdict vervangen. Hosts lezen `$event->comment->counted` voordat ze punten geven.
6. **Events** (na de commit verstuurd) om punten, notificaties en volgers in de host te koppelen: `ReviewSubmitted`, `ReviewPublished`, `ReviewModerated`, `ReviewDeleted`, `CommentPosted` (met `recipients`: `[{user_id, reason: reply|owner}]`, zonder de auteur en zonder geblokkeerden), `CommentEdited`, `CommentModerated`, `CommentDeleted`, `CommentPinned`, `VoteCast`, `ContentReported`, `ReportResolved`, `RatingAggregatesRefreshed`, `ReviewInvitationDue`, `ReviewFollowUpDue` (vervolgvenster open, één keer per review via `rr:send-review-invitations`), `ReviewFollowUpPosted`, `WantToTryRequested` (namespace `RatingsAndReviews\Events`).
7. **Vijfde ronde** (stemantwoord, `uncounted_reason`, deelname per soort, stemindexen, `recountForUsers`): zie de migratienotitie in [`docs/architectuur.md`](docs/architectuur.md#vijfde-ronde-3-november-2026).
8. **Oude systemen vervangen** (WelZoLekker, NutriCompare): zie de migratienotitie van de vierde ronde in [`docs/architectuur.md`](docs/architectuur.md). In het kort: `isOpenForComments` → `acceptsComments`, `isDiscussionVisibleTo` → `commentsVisibleTo`, `canJoinDiscussion` → `ParticipationRules::canParticipate`, `holdsCommentFrom` → `holdsContributionFrom`, `hidesCommentsOfBlockedMembers` → `HidesBlockedMembers`, `canModerateDiscussion` → `ScopedModeration`, `discussionOwner` → `HasDiscussionOwner` + `CommentPosted::$recipients`, `syncCommentCount` → `SyncsCommentCount`, `discussionContextUrl` → `HasDiscussionContext`, `UserBlock::existsBetween` → `BlockList`, "hats"/`brand_account_id` → `AttributionResolver`, officiële antwoorden/pins → `attribution` + `POST comments/{id}/pin`, vragenlijst per categorie → reviewvragen, "na een maand" → vervolgen, review-Q&A → reacties op reviews, `MIN_REVIEWS_FOR_AVERAGE` → `min_reviews_for_average`, de host-middleware voor alleen-scoren → `reviews_enabled`.

## Tests

De tests draaien op MySQL 8.4 (geen SQLite, geen Docker). Zoeken en de gelijktijdigheidstests hebben gecommitte data nodig en draaien op een eigen schema `<DB_DATABASE>_committed`, dat de tests zelf aanmaken (de gebruiker moet `CREATE DATABASE` mogen); de gelijktijdigheidstests gebruiken `pcntl`. Standaard: `127.0.0.1:3306`, gebruiker `root` zonder wachtwoord, schema `rr_test`; overschrijfbaar met `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint
```

## Frontend

Drie lagen, met dezelfde class-namen (`rr-…`) en dezelfde stylesheet:

| Laag | Waar | Voor |
|---|---|---|
| Gedeelde ES-modules | `resources/js/` | API-client (CSRF), één mapping van de API-JSON (`normalize.js`), Tiptap-editorfabriek per sectie (StarterKit, Link, Underline, Image alleen eigen uploads, Sticker, ProductCard `data-rr-card="type:id"`, QuoteReply `data-rr-quote="id"`), DOMPurify-renderhulp, kwaliteitsmeter (identiek aan `ReviewQualityMeter`), tone-check-client, peilingvalidatie, undo-countdown, concepten. Geen build: de host bundelt ze. |
| Blade (WelZoLekker) | `resources/views/components/` + `resources/js/blade/` | `<x-rr::rating-summary>`, `<x-rr::reviews>`, `<x-rr::review-form>`, `<x-rr::discussion>`, `<x-rr::editor>`, `<x-rr::topic-ratings>`, kaartuitbreidingen (`Support\CardExtensions`, `registerCardExtension`) |
| Vue 3 (NutriCompare) | `packages/vue/` → npm `@jw-1980/ratings-and-reviews-vue` | `RrRatingSummary`, `RrReviews`, `RrReviewForm`, `RrDiscussion`, `RrEditor`, `RrTopicRatings` (plus de onderdelen) |

Peer-dependencies (in de host): `@tiptap/core`, `@tiptap/pm`, `@tiptap/starter-kit`, `@tiptap/extension-image` `~3.31`, `dompurify` `^3` en voor Vue `vue` `^3.5`. Geen AI, nergens.

### WelZoLekker (Blade)

1. De componenten roepen de API aan met de sessie van de pagina. Zet daarom in `config/ratings-and-reviews.php` `routes.middleware` op `['web']` (sessie-auth en CSRF), en zorg voor `<meta name="csrf-token" content="{{ csrf_token() }}">` in de layout (anders gaat het `XSRF-TOKEN`-cookie mee).
2. Peers installeren: `npm i @tiptap/core@~3.31 @tiptap/pm@~3.31 @tiptap/starter-kit@~3.31 @tiptap/extension-image@~3.31 dompurify@^3`.
3. In `resources/js/app.js` van de host:

   ```js
   import '../../vendor/jw-1980/ratings-and-reviews/resources/js/css/ratings-and-reviews.css';
   import '../../vendor/jw-1980/ratings-and-reviews/resources/js/css/themes/welzolekker.css'; // of eigen --rr-* waarden
   import { enhanceRatingsAndReviews } from '../../vendor/jw-1980/ratings-and-reviews/resources/js/blade/index.js';

   enhanceRatingsAndReviews(document, {
       searchCards: (query) => /* eigen productzoeker */ searchProducts(query), // → [{ type, id, title, url, imageUrl, price }]
       // Optioneel: uploadImage (standaard POST uploads van het pakket) en stickers (standaard GET stickers, geladen bij het eerste openen).
   });
   ```
4. In een view:

   ```blade
   <x-rr::rating-summary :subject="$product" />
   <x-rr::reviews :subject="$product" :login-url="route('login')" :brand-reply="fn (array $review) => view('brands.reply', ['review' => $review])" />
   <x-rr::topic-ratings :subject="$additive" :login-url="route('login')" />
   <x-rr::review-form :subject="$product" :login-url="route('login')">
       <x-slot:comparison-picker>@include('products.compare-picker')</x-slot:comparison-picker>
   </x-rr::review-form>
   <x-rr::discussion :subject="$product" :login-url="route('login')" />
   ```

   De componenten renderen op de server via de pakketroutes (in-process, met de rechten en vormen van de API). Zonder JavaScript werken lezen, sorteren, filteren (`?rr_rating`, `?rr_facet[groep]`, `?rr_photos=1`, `?rr_sort`, `?rr_page`; reacties op een review `?rr_rthread=ID`, getoond onder de lijst met voorvoegsel `rr_r`; discussie `?rr_csort`, `?rr_cpage`, `?rr_cq`, `?rr_creply`), zoeken, antwoorden en "Meer antwoorden tonen" (`?rr_cthread=ID&rr_cthread_pages=N`). Met JavaScript gebeurt hetzelfde zonder herladen. Het voorvoegsel is instelbaar met `prefix`. Het reviewformulier volgt `review_extended_fields` (titel, plus- en minpunten), `review_min_length` en `reviews.max_photos` (foto's: met JavaScript direct geüpload, zonder als bestanden meegestuurd).

**Annotaties op kaarten (gezondheidsclaim, betrouwbaarheid, flair).** Het pakket kent geen enkele annotatie zelf; de host bindt een `Contracts\ContentAnnotator` (standaard `NullContentAnnotator`) en markeert zo reviews en reacties. De annotator wordt één keer per lijst aangeroepen met alle items die de lijst toont (de reviews van de pagina plus de eigen review; alle reacties van de discussiepagina inclusief geneste antwoorden en de opgehaalde antwoordpagina's; een net opgeslagen review of reactie), nooit per item, zodat een host in bulk kan detecteren of opzoeken. Placeholders van verwijderde reacties worden niet doorgegeven. De sleutel van het resultaat is `Services\Annotations::keyOf($item)` (`review:12`, `comment:34`):

```php
use RatingsAndReviews\Contracts\ContentAnnotator;
use RatingsAndReviews\Services\Annotations;
use RatingsAndReviews\Support\Annotation;

class HealthClaimAnnotator implements ContentAnnotator
{
    public function annotate(array $items, ?Authenticatable $viewer): array
    {
        $marks = [];

        foreach ($items as $item) { // Review|Comment: $item->body_text, $item->user_id, …
            if (app(HealthClaimDetector::class)->detect((string) $item->body_text)->flagged()) {
                $marks[Annotations::keyOf($item)][] = new Annotation('health_claim', __('claims.badge'), Annotation::WARNING, route('claims.help'));
            }
        }

        return $marks;
    }
}
// Host-ServiceProvider::register(): $this->app->singleton(ContentAnnotator::class, HealthClaimAnnotator::class);
```

`Annotation(key, label, tone = 'info', url = null)`: `key` is een stabiele sleutel voor stijl en tests (`data-rr-annotation`), `label` platte tekst (HTML wordt weggehaald, maximaal 120 tekens, de host vertaalt zelf), `tone` `info`, `warning` of `success` (anders `info`), `url` optioneel en door `SafeUrl` (alleen http(s) of relatief). Hoogstens 5 per item, dubbele sleutels vallen weg, onbekende items en niet-`Annotation`-waarden worden genegeerd. Het resultaat is voor elke kijker van het item zichtbaar; wat alleen moderators mogen zien hoort er niet in (gebruik `$viewer`). Een fout in de annotator wordt met `report()` gemeld en de lijsten verschijnen zonder annotaties (met `app.debug` aan wordt hij gegooid).

Elke review en reactie in de API heeft `annotations` (`[{key, label, tone, url}]`, leeg zonder annotator). **Auteurbadges** (betrouwbaarheid, flair) komen uit het bestaande `AuthorProfile::$extra`: `extra['badges']` is een lijst strings of `{label, tone?, url?, key?, icon?}`; het pakket normaliseert ze in `author.extra.badges` (zelfde vorm als annotaties, plus `icon`; ze staan ook op de hovercard). Blade (`partials/annotations`) en Vue (`RrAnnotations`, props `annotations` en `author`) tonen de auteurbadges gevolgd door de annotaties als `<ul class="rr-annotations">` in de kopregel van de kaart, na de attributiebadge. Toegankelijk: een lijst met label ("Notities"), een pictogram (`ℹ`, `⚠`, `✓`) en een visueel verborgen woord voor de toon ("Waarschuwing: …"), zodat de toon niet van kleur alleen afhangt; kleuren komen uit de themavariabelen (`--rr-color-warning(-soft)`, `--rr-color-success`, `--rr-color-text`). Zoekresultaten en de moderatie-inbox hebben geen annotaties.

**Posten zonder JavaScript.** Zet `web_routes.enabled` op `true`: het pakket registreert dan formulierroutes onder `/{web_routes.prefix}` (standaard `rr-forms`, routenamen `rr.web.*`, middleware `web` plus `web_routes.middleware`) voor reviews (met foto's als `photo_files[]`, antwoorden als `answers[sleutel]`/`answers[sleutel][]` en `attribution`), vervolgen (`POST|DELETE rr-forms/reviews/{id}/follow-up`), vastzetten (`POST|DELETE rr-forms/comments/{id}/pin`), reacties, bewerken, verwijderen (met bevestiging in een `<details>`), ongedaan maken, stemmen, peilingstemmen en meldingen. `<x-rr::topic-ratings>` post alleen via deze routes (`PUT rr-forms/subjects/{type}/{id}/topic-ratings`, error bag `rr_topic_ratings`). Zonder `brand-reply` toont de reviewkaart de `official_reply` van de API. Elke route draait de API-controller in-process en stuurt terug naar de pagina: `rr_status` bij succes, fouten in een eigen error bag per formulier (`rr_review`, `rr_comment`, `rr_report`, `rr_vote`) met de oude invoer, `rr_form` om te weten bij welk formulier ze horen, `rr_tone` bij de herformuleer-suggestie (het formulier biedt dan "Toch plaatsen") en `rr_error` bij 401/403/404/429. De Blade-componenten gebruiken deze routes automatisch; bewerken zonder JavaScript gaat via `?rr_cedit=ID`. Met JavaScript blijven dezelfde formulieren via de JSON-API werken. `action`/`update-action` overschrijven de URL nog steeds met een eigen hostroute (die dan zelf `$errors` en `session('rr_status')` moet vullen).

**Kaartuitbreidingen (host-features op review- en reactiekaarten).** Een host rendert eigen features, zoals de emoji-reacties en kudos van WelZoLekker, in de kaarten van het pakket via `RatingsAndReviews\Support\CardExtensions`. Posities: `review.meta` (einde van de kopregel, inline), `review.footer` (naast de nuttig-stem), `comment.meta` en `comment.footer`. Een renderer krijgt de kaart als API-array (`ReviewResource`/`CommentResource`, dezelfde vorm als bij `brand-reply`), de kijker (`null` voor een gast) en het resultaat van de preload met dezelfde sleutel; hij geeft een `Htmlable` (view, `HtmlString`: ongewijzigd geprint), een string (ge-escaped) of `null` (niets). Een preload draait één keer per lijst (alle reviews van de pagina inclusief de eigen review, of alle reacties van de discussiepagina inclusief antwoorden, zonder verwijderde placeholders) en alleen als op die positie iets geregistreerd is. Dezelfde sleutel opnieuw registreren vervangt de vorige. Host-views kunnen ook via de config: `card_extensions.views` (`['review.footer' => ['reactions.review-bar']]`, met `$item`/`$review`/`$comment`, `$viewer`, `$preloaded` en `$position`). Een fout in host-code wordt met `report()` gemeld en laat alleen die uitbreiding weg; met `app.debug` aan wordt hij gegooid.

```php
// Host-ServiceProvider::boot() (WelZoLekker)
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use RatingsAndReviews\Support\CardExtensions;

foreach (['review' => 'rr_review', 'comment' => 'rr_comment'] as $kind => $type) {
    // Eén query per lijst: tellingen per emoji en de eigen reacties van de kijker.
    CardExtensions::preload("{$kind}.footer", fn (Collection $items, ?Authenticatable $viewer) => Reaction::summaryFor($type, $items->pluck('id'), $viewer?->getAuthIdentifier()), 'reactions');
    CardExtensions::register("{$kind}.footer", fn (array $item, ?Authenticatable $viewer, array $summary) => view('reactions.bar', [
        'type' => $type,
        'id' => $item['id'],
        'counts' => $summary[$item['id']]['counts'] ?? [],
        'mine' => $summary[$item['id']]['mine'] ?? [],
        'canReact' => $viewer !== null && ! $item['is_own'] && $item['status'] === 'approved',
    ]), 'reactions');
}

CardExtensions::preload('review.meta', fn (Collection $reviews) => Kudos::totalsForUsers($reviews->pluck('author.id')->filter()), 'kudos');
CardExtensions::register('review.meta', fn (array $review, ?Authenticatable $viewer, array $totals) => isset($review['author']['id'], $totals[$review['author']['id']])
    ? view('kudos.badge', ['total' => $totals[$review['author']['id']]])
    : null, 'kudos');
```

De wrapper is `<div|span class="rr-extensions rr-extensions--footer|meta" data-rr-extension="review.footer" data-rr-item-type="review" data-rr-item-id="12">`. Met JavaScript rendert de verrijking na posten, bewerken, verwijderen, sorteren, filteren en pagineren de componenten opnieuw op de server en wisselt ze om ("Meer reacties" haalt de volgende pagina als HTML), dus de host-uitbreidingen staan altijd in die server-HTML. Gedrag (klikken, tellers bijwerken) koppelt de host met een JS-haak, die voor elk uitbreidingselement één keer draait, ook voor elementen die later binnenkomen:

```js
import { enhanceRatingsAndReviews, registerCardExtension } from '../../vendor/jw-1980/ratings-and-reviews/resources/js/blade/index.js';

const bindReactions = (item, element) => { // item: { position, type: 'review'|'comment', id, subject: { type, id }, card }
    element.querySelectorAll('[data-reaction]').forEach((button) => button.addEventListener('click', () => toggleReaction(item.type, item.id, button)));
};
enhanceRatingsAndReviews(document, { searchCards, cardExtensions: { 'review.footer': bindReactions, 'comment.footer': bindReactions } });
// of later: const off = registerCardExtension('comment.footer', bindReactions);
```

Vendor-overschrijvingen (`resources/views/vendor/rr/partials/attribution.blade.php` voor de zakelijke badge, enzovoort) blijven werken; een oudere overschrijving van `partials/review-card` of `partials/comment` zonder de twee `render()`-aanroepen toont gewoon geen uitbreidingen.

### NutriCompare (Vue 3)

Het Vue-pakket staat op GitHub Packages (npm.pkg.github.com), zie [Vue-pakket installeren](#vue-pakket-installeren-github-packages).

```bash
npm i @jw-1980/ratings-and-reviews-vue @tiptap/core@~3.31 @tiptap/pm@~3.31 @tiptap/starter-kit@~3.31 @tiptap/extension-image@~3.31 dompurify@^3
```

```php
// HandleInertiaRequests::share()
'rr' => [
    'messages' => trans('ratings-and-reviews::ui'),
    'reportReasons' => collect(config('ratings-and-reviews.reports.reasons'))->map(fn ($r) => ['value' => $r, 'label' => __('ratings-and-reviews::enums.report_reason.'.$r)])->values(),
],
```

```js
import { createRatingsAndReviews } from '@jw-1980/ratings-and-reviews-vue';
import '@jw-1980/ratings-and-reviews-vue/style.css';
import '@jw-1980/ratings-and-reviews-vue/themes/nutricompare.css';

app.use(createRatingsAndReviews({
    messages: page.props.rr.messages,
    locale: page.props.locale,                 // 'nl' of 'en'
    api: { baseUrl: '/rr' },                   // csrfToken of headers: { Authorization } voor tokens
    isAuthenticated: Boolean(page.props.auth.user),
    loginUrl: route('login'),
    reportReasons: page.props.rr.reportReasons,
    mediaPrefixes: ['/storage/'],              // bij uploads.visibility = private ook '/rr/media/'
    searchCards,                               // optioneel: uploadImage (standaard POST uploads) en stickers (standaard GET stickers)
}));
```

```vue
<RrRatingSummary :subject="{ type: 'product', id }" v-model:rating="rating" v-model:facets="facets" />
<RrReviews :subject="{ type: 'product', id }" v-model:rating="rating" v-model:facets="facets" :topics="topics" @loaded="page = $event" />
<RrReviewForm :subject="{ type: 'product', id }" :meta="page.meta" :topics="topics" :facet-options="page.meta.facets"
              :comparisons="page.meta.comparisons" :tools="page.meta.editor.editorTools ?? undefined" :review="page.meta.ownReview">
    <template #comparison-picker="{ select }"><ProductPicker @pick="select" /></template>
</RrReviewForm>
<RrDiscussion :subject="{ type: 'product', id }" />
<RrTopicRatings :subject="{ type: 'additive', id }" />
```

Met `:meta` volgt `RrReviewForm` de sectie (schaal, uitgebreide velden, minimale lengte, foto's en lengtelimieten, reviewvragen `meta.questions`, "plaatsen als" `meta.attributions`; met `reviews_enabled` uit of zonder deelname een melding in plaats van het formulier); losse props (`scale`, `extended-fields`, `min-length`, `max-photos`, `max-title`, `max-points`) winnen als ze gegeven zijn. `RrDiscussion` toont bij een afgekapte draad "Meer antwoorden tonen" en laadt de rest per pagina. `RrReviewCard` toont de badge (`RrAttributionBadge`), waarom de review niet meetelt (een publieke reden als label, anders een melding voor de auteur of moderator), de antwoorden, het vervolg met formulier voor de auteur (`RrFollowUp`, event `updated`) en de blokkade-melding, en `officialReply` (het slot `brand-reply` wint) en opent met "Reacties tonen" een `RrDiscussion` op `{ type: 'rr_review', id }`; `RrReviews` heeft het filter "Met foto's". `RrDiscussion` zet de vastgezette reactie bovenaan, biedt moderators en de eigenaar "Bovenaan vastzetten"/"Losmaken" en een "plaatsen als"-keuze in de composer. De API-client heeft ook `createFollowUp`, `deleteFollowUp`, `pinComment`, `adminReviewQuestions`, `createReviewQuestion`, `updateReviewQuestion`, `deleteReviewQuestion`, `uploadImage`, `replies`, `stickers`, `topicRatings`, `saveTopicRatings`, `reviewRepliesSubject` en voor beheer `adminStickers`, `createSticker`, `updateSticker`, `deleteSticker`.

**Kaartuitbreidingen in Vue.** Dezelfde vier posities. Per kaart als slot, gescoped met het item: `RrReviewCard` `#meta`/`#footer` (`{ review, item }`) en `#comment-meta`/`#comment-footer` (voor de antwoorden op de review), `RrComment` `#meta`/`#footer` (`{ comment, item }`, doorgegeven aan alle antwoorden), `RrReviews` `#review-meta`/`#review-footer`/`#comment-meta`/`#comment-footer` en `RrDiscussion` `#comment-meta`/`#comment-footer`. Of voor de hele app in de plugin, met een optionele `preload` die de lijsten één keer per geladen pagina aanroepen (eerste pagina met de eigen review, elke volgende pagina, een pagina antwoorden, een nieuw geplaatste reactie; zonder verwijderde placeholders). De terugkeerwaarde van `preload` wordt niet doorgegeven: de host vult zijn eigen store en de component leest die. Een slot vervangt op die kaart de geregistreerde componenten; zonder slot en registratie is er geen wrapper. De componenten krijgen de props `item` (genormaliseerde `Review`/`Comment`) en `position`.

```js
app.use(createRatingsAndReviews({
    // …
    cardExtensions: {
        'review.footer': { component: ReactionBar, preload: (reviews) => reactions.load('rr_review', reviews.map((r) => r.id)) },
        'comment.footer': { component: ReactionBar, preload: (comments) => reactions.load('rr_comment', comments.map((c) => c.id)) },
        'review.meta': KudosBadge,
    },
}));
```

```vue
<RrReviews :subject="{ type: 'product', id }">
    <template #review-footer="{ review }"><ReactionBar :item="review" position="review.footer" /></template>
</RrReviews>
```

Alle API-vormen staan getypeerd in `packages/vue/src/types.ts`; de mapping van de server-JSON zit op één plek, `resources/js/normalize.js`.

### Vue-pakket installeren (GitHub Packages)

`@jw-1980/ratings-and-reviews-vue` wordt gepubliceerd op GitHub Packages. De host haalt het met `npm ci` binnen, zonder PHP-`vendor`-map. Het pakket is privé: elke installatie heeft een token nodig met `read:packages` (een classic personal access token, of in GitHub Actions een `GITHUB_TOKEN` met `packages: read` van een repository die toegang tot het pakket heeft).

Zet in de host naast `package.json` een `.npmrc` (zonder token erin, die blijft een omgevingsvariabele):

```ini
@jw-1980:registry=https://npm.pkg.github.com
//npm.pkg.github.com/:_authToken=${NODE_AUTH_TOKEN}
```

- Lokaal: `export NODE_AUTH_TOKEN=<token met read:packages>` en daarna `npm ci`.
- CI (GitHub Actions): `actions/setup-node` met `registry-url: https://npm.pkg.github.com` en `scope: '@jw-1980'`, en `NODE_AUTH_TOKEN: ${{ secrets.GITHUB_TOKEN }}` (of een eigen secret met `read:packages`) bij de stap `npm ci`.
- Docker-builds: geef het token als build-secret door (`docker build --secret id=npm_token,env=NODE_AUTH_TOKEN`, in de Dockerfile `RUN --mount=type=secret,id=npm_token NODE_AUTH_TOKEN="$(cat /run/secrets/npm_token)" npm ci`). Zet het token niet in een `ARG`/`ENV`, want dan staat het in de imagelagen.
- Commit nooit een `.npmrc` met een echt token.

Het npm-pakket bevat alleen `dist/` (ES-module, CSS, thema's, `.d.ts`); de peer-dependencies (`vue`, Tiptap, `dompurify`) installeert de host zelf.

### Release

De versie van het Vue-pakket (`packages/vue/package.json`) is gelijk aan de PHP-tag van het pakket. Stappen:

1. Zet `version` in `packages/vue/package.json` (en `package-lock.json`, bijvoorbeeld met `npm version X.Y.Z --no-git-tag-version` in `packages/vue`) op `X.Y.Z` en commit die wijziging samen met de rest van de release op `main`.
2. Wacht tot de CI-workflow (`.github/workflows/ci.yml`) groen is.
3. Tag die commit: `git tag vX.Y.Z && git push origin vX.Y.Z`. Composer leest de PHP-versie uit deze tag.
4. De workflow `.github/workflows/publish-vue.yml` draait op de tag: `npm ci`, tests, typecheck, controle dat de tag gelijk is aan `package.json` (anders faalt hij) en daarna `npm publish` naar GitHub Packages. `prepack` bouwt `dist/`, de thema's en de `.d.ts` vlak vóór het publiceren.
5. Een versie op GitHub Packages kan niet opnieuw worden gepubliceerd: bij een fout verhoog je de versie en tag je opnieuw.

### Thema

Alles loopt via CSS custom properties met neutrale standaarden (op `:where(:root)`, dus elke hostregel wint). De belangrijkste: `--rr-font-family`, `--rr-color-text`, `--rr-color-muted`, `--rr-color-surface`, `--rr-color-surface-alt`, `--rr-color-border`, `--rr-color-primary`, `--rr-color-primary-contrast`, `--rr-color-primary-soft`, `--rr-color-accent` (sterren en balken), `--rr-color-success|warning|danger(-soft)`, `--rr-color-new`, `--rr-color-focus`, `--rr-color-bar`, `--rr-color-mark`, `--rr-radius`, `--rr-shadow`, `--rr-target-size` (44px) en `--rr-indent`. Voorbeelden: `resources/js/css/themes/welzolekker.css` (framboos `#8a2250`, Geist) en `nutricompare.css` (teal `#005d5d`, Plus Jakarta Sans). Lettertypen laadt de host zelf. `prefers-reduced-motion` en `forced-colors` worden gerespecteerd.

### Vertalingen

Alle UI-teksten staan in `lang/{nl,en}/ui.php` (gelijke sleutels). Blade leest ze direct (`__('ratings-and-reviews::ui.…')`). De JavaScript-kant krijgt hetzelfde bestand als `messages`-object: de Blade-componenten zetten het één keer in de pagina (`<script type="application/json" id="rr-messages">`), een Vue-host geeft `trans('ratings-and-reviews::ui')` door aan de plugin. Er is geen JSON-endpoint en geen terugvaltaal: een ontbrekende sleutel verschijnt als sleutel en wordt in de console gemeld. Syntax zoals Laravel (`:naam`, `{0}…|{1}…|[2,*]…`).

### Ontwikkelen en testen

```bash
cd packages/vue
npm install
npm test            # Vitest (kopieert eerst resources/js naar src/shared)
npm run typecheck   # vue-tsc
npm run build       # dist/: ES-module, CSS, thema's en .d.ts
```

- `php packages/vue/scripts/quality-fixture.php` schrijft `tests/fixtures/review-quality.json` uit de echte PHP-regels en -scores; de parity-spec rekent dezelfde gevallen in JavaScript na.
- `DB_DATABASE=rr_ui_views vendor/bin/phpunit --bootstrap vendor/autoload.php packages/vue/scripts/blade-fixture/BladeComponentsRenderTest.php` rendert de Blade-componenten op MySQL 8.4 en schrijft de HTML waartegen `tests/blade.spec.ts` de Blade-verrijking test; `BladeFormRoutesRenderTest.php` (zelfde aanroep) doet dat met de formulierroutes aan voor `tests/blade-forms.spec.ts`.
- `node_modules` staat alleen in `packages/vue` (genegeerd door git). Het PHP-pakket heeft bij installatie geen JavaScript-build nodig.

Nog niet in de API, dus in de UI alleen als host-callback: productzoeken voor `#` (host: `searchCards`; zonder zoeker werkt `#type:id`). Merkreacties komen nu uit `OfficialReplyResolver` (`official_reply`); het slot `brand-reply` blijft als overschrijving.
