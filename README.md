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

Vereisten: PHP 8.3+, Laravel 12 of 13, MySQL 8.4. Publiceerbare tags: `ratings-and-reviews-config`, `-migrations`, `-translations`, `-views`.

## Gebruik

1. **Subjects.** Implementeer `RatingsAndReviews\Contracts\Reviewable` en/of `Commentable` op een host-model, gebruik de traits `RatingsAndReviews\Concerns\HasReviews` en `HasComments`, en registreer het model in de morph map van de host (`Relation::enforceMorphMap(['product' => Product::class])`). De API kent alleen aliassen uit de morph map; elke andere `{type}` geeft 404.
2. **Contracts binden** in een ServiceProvider van de host. Elk contract heeft een veilige standaard:

   | Contract | Standaard |
   |---|---|
   | `AbilityResolver` | vraagt de Gate; abilities `rr.moderate`, `rr.manage-settings`, `rr.manage-topics` (zie `Support\Ability`). Niet gedefinieerd = geweigerd. |
   | `AuthorResolver` | naam uit het user-model (`authors.model`/`name_attribute`), geen avatar |
   | `RiskSignals` | alles telt, niets wacht (bind een device-id-implementatie) |
   | `VoterIdentity` | `u:<id>` voor leden, `g:<hmac>` van netwerk + user agent voor gasten |
   | `PriceAtReviewResolver`, `CardResolver`, `WantToTryList` | uitgeschakeld (`isEnabled()` is false) |
   | `ContributionCounter` | telt goedgekeurde reviews en reacties in de rr_-tabellen |

   ```php
   $this->app->singleton(\RatingsAndReviews\Contracts\RiskSignals::class, DeviceIdRiskSignals::class);
   Gate::define('rr.moderate', fn (User $user) => $user->hasPermission('moderate'));
   ```
3. **Config** (`config/ratings-and-reviews.php`): `routes` (prefix `rr`, middleware, admin-middleware, guard), `rate_limits` (named limiters `rr-write`, `rr-vote`, `rr-report`, `rr-admin`), `section_defaults` en `sections.<naam>` (diepte, editor-tools, stemdimensies, gaststemmen, sortering, lengtes, bewerktermijn, reviewschaal, …; een admin overschrijft ze per sectie via `PATCH admin/sections/{section}`), `rating_topics.max_active`, `reports.reasons`, `sanitizer`, `uploads`/`stickers` (url-prefix voor toegestane afbeeldingen) en `spam` (woordenlijsten en drempels).
4. **API** onder `/{prefix}`. Alle schrijfroutes hebben een throttle; zoeken, kaarten, hovercards en de toon-check hebben de limiter `rr-lookup`.

   | Route | Feature |
   |---|---|
   | `GET subjects/{type}/{id}/reviews` | lijst; `sort`, `rating`, `facet[groep]=waarde`; `meta` met facetten, editor-vertrouwen (`meta.editor`) en `features` |
   | `POST subjects/{type}/{id}/reviews`, `PATCH`/`DELETE reviews/{id}` | met `facets`, `compared_type`/`compared_id`/`comparison`, `confirm_tone`; antwoord bevat `quality` |
   | `GET subjects/{type}/{id}/rating-summary` | plus `uncertainty`, `trend`, `facets`, `comparisons` |
   | `GET subjects/{type}/{id}/comments` | boom met `visibility` (eigen pending/gepland), `quote`, `poll`, `is_new`; `meta.trust`, `meta.discussion`, `meta.new_since` |
   | `POST subjects/{type}/{id}/comments` | met `quoted_comment_id` + `quote_text`, `poll`, `confirm_tone` |
   | `POST comments/{id}/undo` | ongedaan maken binnen `undo_window_seconds` |
   | `GET subjects/{type}/{id}/digest` | TL;DR (extractief, vanaf `digest_threshold`) |
   | `GET subjects/{type}/{id}/search?q=&in=` | FULLTEXT-zoeken met `<mark>`-fragmenten |
   | `POST subjects/{type}/{id}/want-to-try` | "Wil ik proberen" (404 zonder `WantToTryList`) |
   | `GET polls/{id}`, `POST polls/{id}/votes` | mini-peiling |
   | `GET authors/{id}/card`, `GET cards/{type}/{id}` | hovercard, productkaart (404 zonder `CardResolver`) |
   | `POST tone-check`, `GET review-quality-rules` | herformuleer-suggestie, regels van de kwaliteitsmeter (JSON) |
   | `GET review-invitations`, `POST review-invitations/{id}/dismiss` | open uitnodigingen van het lid |
   | `GET|PATCH admin/sections/{section}`, `admin/topics`, `admin/reports`, `admin/moderation` | beheer |
   | `GET|PATCH admin/discussions/{type}/{id}` | slow mode en vergrendelen (`rr.moderate`) |

5. **Features van versie 1** (allemaal deterministisch, geen AI):
   - TL;DR van lange discussies, zoeken binnen een subject, prijs op het moment van de review (`PriceAtReviewResolver`), "Vergeleken met…", kwaliteitsmeter (`quality` in de config), review-uitnodigingen (`ReviewInvitations::schedule()`), context-facetten (`reviews.facets`), onzekerheidsband en scoreverloop in de samenvatting, sorteren op "Controversieel" (reacties), citeer-selectie, productkaart, mini-peiling (`polls`), "Nieuw sinds je laatste bezoek", slow mode en vergrendelen, vertrouwensniveaus (`trust_levels` per sectie), herformuleer-suggestie (`tone.words`), profiel-hovercard, ongedaan maken na plaatsen (`undo_window_seconds`), "Wil ik proberen" (`WantToTryList`).
   - **Commands** (automatisch ingepland, uit te zetten met `schedule.enabled`): `rr:publish-due` (elke minuut: reacties na het venster publiceren, daarna pas `CommentPosted`) en `rr:send-review-invitations` (elk uur: `ReviewInvitationDue`).
   - `RiskSignals` kreeg `forPollVote()`. Een bijdrage met `counted=false` blijft zichtbaar, maar telt niet als bijdrage (vertrouwensniveaus, TL;DR, topreactie); bij reviews telt hij ook niet mee in de aggregaten. Hosts lezen `$event->comment->counted` voordat ze punten geven.
6. **Events** (na de commit verstuurd) om punten, notificaties en volgers in de host te koppelen: `ReviewSubmitted`, `ReviewPublished`, `ReviewModerated`, `ReviewDeleted`, `CommentPosted`, `CommentEdited`, `CommentModerated`, `CommentDeleted`, `VoteCast`, `ContentReported`, `ReportResolved`, `RatingAggregatesRefreshed`, `ReviewInvitationDue`, `WantToTryRequested` (namespace `RatingsAndReviews\Events`).

## Tests

De tests draaien op MySQL 8.4 (geen SQLite, geen Docker). Zoeken en de gelijktijdigheidstests hebben gecommitte data nodig en draaien op een eigen schema `<DB_DATABASE>_committed`, dat de tests zelf aanmaken (de gebruiker moet `CREATE DATABASE` mogen); de gelijktijdigheidstests gebruiken `pcntl`. Standaard: `127.0.0.1:3306`, gebruiker `root` zonder wachtwoord, schema `rr_test`; overschrijfbaar met `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint
```
