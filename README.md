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

Vereisten: PHP 8.3+, Laravel 12 of 13, MySQL 8.4; `ext-gd` (aanbevolen) om EXIF uit geüploade foto's te halen, zonder GD worden ze ongewijzigd opgeslagen. Publiceerbare tags: `ratings-and-reviews-config`, `-migrations`, `-translations`, `-views`.

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
3. **Config** (`config/ratings-and-reviews.php`): `routes` (prefix `rr`, middleware, admin-middleware, guard), `web_routes` (opt-in formulierroutes zonder JavaScript, zie Frontend), `rate_limits` (named limiters `rr-write`, `rr-vote`, `rr-report`, `rr-admin`, `rr-lookup`, `rr-upload`), `section_defaults` en `sections.<naam>` (diepte, editor-tools, stemdimensies, gaststemmen, sortering, lengtes, bewerktermijn, reviewschaal, uitgebreide reviewvelden, minimale reviewlengte, …; een admin overschrijft ze per sectie via `PATCH admin/sections/{section}`), `discussions` (`replies_per_request`), `reviews` (`max_photos`, `photos_require_approval`, lengtes), `rating_topics.max_active`, `reports.reasons`, `sanitizer`, `uploads` (zie hieronder), `stickers` (`url_prefix`, `disk`, `directory`, limieten) en `spam` (woordenlijsten en drempels).

   **Uploads** (`uploads.*`): `visibility` `public` (bestanden op `disk`, standaard `public`, door de host geserveerd onder `url_prefix`; `php artisan storage:link`) of `private` (niet-publieke disk; het pakket serveert ze onder `/{prefix}/media/{path}`, aan de uploader en moderators altijd, aan anderen alleen zolang de review of reactie waar ze bij horen publiek is; dat werkt met sessie-auth, niet met bearer tokens in `<img>`). Limieten: `max_kilobytes`, `mime_types` (standaard JPEG, PNG, WebP, GIF; geen SVG), `min_/max_width|height`, `daily_quota` per 24 uur (429 met `Retry-After`) plus `rr-upload` per minuut. `strip_metadata` codeert JPEG/PNG/WebP opnieuw met GD (als die geïnstalleerd is), zodat EXIF-locatie niet gepubliceerd wordt; een JPEG wordt eerst rechtop gezet. `prune_after_hours`: daarna ruimt `rr:prune-uploads` nooit gekoppelde uploads op.
4. **API** onder `/{prefix}`. Alle schrijfroutes hebben een throttle; zoeken, kaarten, hovercards en de toon-check hebben de limiter `rr-lookup`.

   | Route | Feature |
   |---|---|
   | `GET subjects/{type}/{id}/reviews` | lijst; `sort`, `rating`, `facet[groep]=waarde`; `meta` met facetten, editor-vertrouwen (`meta.editor`), `features`, `review_scale`, `review_extended_fields`, `review_min_length` en `review_form` (`max_body_length`, `max_title_length`, `max_pros_cons`, `max_pro_con_length`, `max_photos`, `photos_require_approval`, `requires_approval`); elke review heeft `photos[{id,url,alt,width,height}]` |
   | `POST subjects/{type}/{id}/reviews`, `PATCH`/`DELETE reviews/{id}` | met `facets`, `compared_type`/`compared_id`/`comparison`, `photos` (upload-id's in volgorde; weglaten bij `PATCH` houdt de huidige), `confirm_tone`; titel/pluspunten/minpunten geven 422 als de sectie geen uitgebreide velden heeft; antwoord bevat `quality` |
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
   - `RiskSignals` kreeg `forPollVote()`. Een bijdrage met `counted=false` blijft zichtbaar, maar telt niet als bijdrage (vertrouwensniveaus, TL;DR, topreactie); bij reviews telt hij ook niet mee in de aggregaten. Hosts lezen `$event->comment->counted` voordat ze punten geven.
6. **Events** (na de commit verstuurd) om punten, notificaties en volgers in de host te koppelen: `ReviewSubmitted`, `ReviewPublished`, `ReviewModerated`, `ReviewDeleted`, `CommentPosted`, `CommentEdited`, `CommentModerated`, `CommentDeleted`, `VoteCast`, `ContentReported`, `ReportResolved`, `RatingAggregatesRefreshed`, `ReviewInvitationDue`, `WantToTryRequested` (namespace `RatingsAndReviews\Events`).

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
| Blade (WelZoLekker) | `resources/views/components/` + `resources/js/blade/` | `<x-rr::rating-summary>`, `<x-rr::reviews>`, `<x-rr::review-form>`, `<x-rr::discussion>`, `<x-rr::editor>` |
| Vue 3 (NutriCompare) | `packages/vue/` → npm `@jw-1980/ratings-and-reviews-vue` | `RrRatingSummary`, `RrReviews`, `RrReviewForm`, `RrDiscussion`, `RrEditor` (plus de onderdelen) |

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
   <x-rr::reviews :subject="$product" :brand-reply="fn (array $review) => view('brands.reply', ['review' => $review])" />
   <x-rr::review-form :subject="$product" :login-url="route('login')">
       <x-slot:comparison-picker>@include('products.compare-picker')</x-slot:comparison-picker>
   </x-rr::review-form>
   <x-rr::discussion :subject="$product" :login-url="route('login')" />
   ```

   De componenten renderen op de server via de pakketroutes (in-process, met de rechten en vormen van de API). Zonder JavaScript werken lezen, sorteren, filteren (`?rr_rating`, `?rr_facet[groep]`, `?rr_sort`, `?rr_page`; discussie `?rr_csort`, `?rr_cpage`, `?rr_cq`, `?rr_creply`), zoeken, antwoorden en "Meer antwoorden tonen" (`?rr_cthread=ID&rr_cthread_pages=N`). Met JavaScript gebeurt hetzelfde zonder herladen. Het voorvoegsel is instelbaar met `prefix`. Het reviewformulier volgt `review_extended_fields` (titel, plus- en minpunten), `review_min_length` en `reviews.max_photos` (foto's: met JavaScript direct geüpload, zonder als bestanden meegestuurd).

**Posten zonder JavaScript.** Zet `web_routes.enabled` op `true`: het pakket registreert dan formulierroutes onder `/{web_routes.prefix}` (standaard `rr-forms`, routenamen `rr.web.*`, middleware `web` plus `web_routes.middleware`) voor reviews (met foto's als `photo_files[]`), reacties, bewerken, verwijderen (met bevestiging in een `<details>`), ongedaan maken, stemmen, peilingstemmen en meldingen. Elke route draait de API-controller in-process en stuurt terug naar de pagina: `rr_status` bij succes, fouten in een eigen error bag per formulier (`rr_review`, `rr_comment`, `rr_report`, `rr_vote`) met de oude invoer, `rr_form` om te weten bij welk formulier ze horen, `rr_tone` bij de herformuleer-suggestie (het formulier biedt dan "Toch plaatsen") en `rr_error` bij 401/403/404/429. De Blade-componenten gebruiken deze routes automatisch; bewerken zonder JavaScript gaat via `?rr_cedit=ID`. Met JavaScript blijven dezelfde formulieren via de JSON-API werken. `action`/`update-action` overschrijven de URL nog steeds met een eigen hostroute (die dan zelf `$errors` en `session('rr_status')` moet vullen).

### NutriCompare (Vue 3)

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
```

Met `:meta` volgt `RrReviewForm` de sectie (schaal, uitgebreide velden, minimale lengte, foto's en lengtelimieten); losse props (`scale`, `extended-fields`, `min-length`, `max-photos`, `max-title`, `max-points`) winnen als ze gegeven zijn. `RrDiscussion` toont bij een afgekapte draad "Meer antwoorden tonen" en laadt de rest per pagina. De API-client heeft ook `uploadImage`, `replies`, `stickers` en voor beheer `adminStickers`, `createSticker`, `updateSticker`, `deleteSticker`.

Alle API-vormen staan getypeerd in `packages/vue/src/types.ts`; de mapping van de server-JSON zit op één plek, `resources/js/normalize.js`.

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

Nog niet in de API, dus in de UI alleen als slot of verborgen: merkreacties (slot `brand-reply`) en productzoeken voor `#` (host: `searchCards`; zonder zoeker werkt `#type:id`).
