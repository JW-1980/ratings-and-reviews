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
       uploadImage: (file) => /* eigen upload-route */ uploadToHost(file),   // → { url, alt? } onder uploads.url_prefix
       searchCards: (query) => /* eigen productzoeker */ searchProducts(query), // → [{ type, id, title, url, imageUrl, price }]
       stickers: window.rrStickers ?? [],                                      // [{ id, name, url }]
   });
   ```
4. In een view:

   ```blade
   <x-rr::rating-summary :subject="$product" />
   <x-rr::reviews :subject="$product" :brand-reply="fn (array $review) => view('brands.reply', ['review' => $review])" />
   <x-rr::review-form :subject="$product" :action="route('product.reviews.store', $product)" :login-url="route('login')">
       <x-slot:comparison-picker>@include('products.compare-picker')</x-slot:comparison-picker>
   </x-rr::review-form>
   <x-rr::discussion :subject="$product" :action="route('product.comments.store', $product)" :login-url="route('login')" />
   ```

   De componenten renderen op de server via de pakketroutes (in-process, met de rechten en vormen van de API). Zonder JavaScript werken lezen, sorteren, filteren (`?rr_rating`, `?rr_facet[groep]`, `?rr_sort`, `?rr_page`; discussie `?rr_csort`, `?rr_cpage`, `?rr_cq`, `?rr_creply`), zoeken en antwoorden. Met JavaScript gebeurt hetzelfde zonder herladen. Het voorvoegsel is instelbaar met `prefix`.

**Hostverantwoordelijkheid: posten zonder JavaScript.** De API antwoordt met JSON; een gewone formulier-POST heeft een webroute nodig die terugstuurt. `action` is optioneel; zonder `action` meldt het formulier dat JavaScript nodig is. Voorbeeld:

```php
Route::middleware(['web', 'auth'])->group(function () {
    Route::post('/producten/{product}/reacties', function (Product $product) {
        $response = app()->call([app(\RatingsAndReviews\Http\Controllers\CommentController::class), 'store'], ['type' => 'product', 'id' => (string) $product->id]);

        return back()->with('rr_status', $response->getData(true)['message'] ?? null);
    })->name('product.comments.store');
    // idem voor reviews met ReviewController::store (en ::update met _method=PATCH als `update-action`)
});
```

Validatiefouten gaan via de gewone redirect met `$errors`; de componenten tonen `$errors` en `session('rr_status')`.

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
    mediaPrefixes: ['/storage/'],
    stickers, uploadImage, searchCards,        // zoals bij Blade
}));
```

```vue
<RrRatingSummary :subject="{ type: 'product', id }" v-model:rating="rating" v-model:facets="facets" />
<RrReviews :subject="{ type: 'product', id }" v-model:rating="rating" v-model:facets="facets" :topics="topics" @loaded="page = $event" />
<RrReviewForm :subject="{ type: 'product', id }" :scale="page.meta.scaleMax" :topics="topics" :facet-options="page.meta.facets"
              :comparisons="page.meta.comparisons" :tools="page.meta.editor.editorTools ?? undefined" :review="page.meta.ownReview">
    <template #comparison-picker="{ select }"><ProductPicker @pick="select" /></template>
</RrReviewForm>
<RrDiscussion :subject="{ type: 'product', id }" />
```

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
- `DB_DATABASE=rr_ui_views vendor/bin/phpunit --bootstrap vendor/autoload.php packages/vue/scripts/blade-fixture/BladeComponentsRenderTest.php` rendert de Blade-componenten op MySQL 8.4 en schrijft de HTML waartegen `tests/blade.spec.ts` de Blade-verrijking test.
- `node_modules` staat alleen in `packages/vue` (genegeerd door git). Het PHP-pakket heeft bij installatie geen JavaScript-build nodig.

Nog niet in de API, dus in de UI alleen als slot of verborgen: foto's bij reviews, merkreacties (slot `brand-reply`), een uploadroute voor afbeeldingen (host: `uploadImage`), productzoeken voor `#` (host: `searchCards`; zonder zoeker werkt `#type:id`) en `review_extended_fields` in de review-meta (pluspunten, minpunten en titel worden altijd getoond).
