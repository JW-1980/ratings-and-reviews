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
4. **API** onder `/{prefix}`: `subjects/{type}/{id}/reviews|rating-summary|comments`, `reviews/{id}`, `reviews/{id}/votes`, `comments/{id}`, `comments/{id}/revisions`, `comments/{id}/votes`, `reports`, en `admin/sections|topics|reports|moderation`. Alle schrijfroutes hebben een throttle.
5. **Events** (na de commit verstuurd) om punten, notificaties en volgers in de host te koppelen: `ReviewSubmitted`, `ReviewPublished`, `ReviewModerated`, `ReviewDeleted`, `CommentPosted`, `CommentEdited`, `CommentModerated`, `CommentDeleted`, `VoteCast`, `ContentReported`, `ReportResolved`, `RatingAggregatesRefreshed`, `ReviewInvitationDue`, `WantToTryRequested` (namespace `RatingsAndReviews\Events`).

## Tests

De tests draaien op MySQL 8.4 (geen SQLite, geen Docker). Standaard: `127.0.0.1:3306`, gebruiker `root` zonder wachtwoord, schema `rr_test`; overschrijfbaar met `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint
```
