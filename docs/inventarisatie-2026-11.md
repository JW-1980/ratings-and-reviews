# Inventarisatie: reviews, sterren en discussies in WelZoLekker en NutriCompare

Datum: 2 november 2026.

Dit document beschrijft wat WelZoLekker (WZL) en NutriCompare (NC) nu hebben aan reviews, beoordelingen per onderwerp, discussies, stemmen, editor en moderatie. Daarna volgen het samengevoegde ontwerp voor dit pakket, de openstaande to-do's, gevonden gaten en bugs, aanbevelingen en 25 nieuwe features.

**Bron en beperkingen.** Alles is gelezen uit broncode en documentatie van beide repositories op 2 november 2026. Er zijn geen tests of builds gedraaid. In WZL was `vendor/` niet geïnstalleerd, dus de broncode van `jw-1980/device-id` zelf is niet gelezen. Paden zijn relatief aan de root van de betreffende repository.

## Genomen beslissingen

| # | Beslissing | Gekozen |
|---|---|---|
| 1 | Frontend | Eén PHP-pakket met een JSON-API en Blade-componenten (WZL), plus een npm-pakket met Vue 3-componenten (NC). |
| 2 | Editor en sanitizer | Beide apps gebruiken dezelfde: Tiptap als editor, `symfony/html-sanitizer` op de server, DOMPurify in de browser. |
| 3 | Pakket | `jw-1980/ratings-and-reviews` in deze repository, met semver-tags. |
| 4 | Features voor de eerste versie | Gekozen via de beslispagina op 2 november 2026; zie [Gekozen features](#gekozen-features). |
| 5 | Startvolgorde | A: eerst de HIGH- en MEDIUM-bugs repareren en een morph map invoeren in beide apps, daarna het pakket. |

### Gekozen features

Voorwaarde bij alles: **geen AI**. Features die een taalmodel nodig zouden hebben, worden deterministisch gebouwd (regels, woordenlijsten, tellingen) of niet.

- **Versie 1 (20):** TL;DR van lange discussies (zonder AI), zoeken binnen reviews en discussies, prijs op het moment van de review, "Vergeleken met…", kwaliteitsmeter tijdens het schrijven (zonder AI), review-uitnodiging na aankoop, context-facetten, onzekerheidsband, scoreverloop, sorteren op "Controversieel", citeer-selectie, productkaart in de editor, mini-peiling in een reactie, "Nieuw sinds je laatste bezoek", slow mode, vertrouwensniveaus, herformuleer-suggestie (zonder AI), profiel-hovercard, ongedaan maken na plaatsen, "Wil ik proberen".
- **Later (3):** workflow voor "bron nodig", alt-tekst bij afbeeldingen (zonder AI), openbaar moderatielogboek.
- **Niet doen (2):** AI-samenvatting van reviews, foto-annotaties.

## In het kort

- **Beide apps hebben het al, maar elk anders.** Allebei hebben ze sterren per onderwerp (max. 4), threaded reacties, een editor met instellingen per sectie, stemmen als eens/oneens/nuttig, meldingen en fraudecontrole. De code, het datamodel en de frontend verschillen volledig.
- **Andere stack.** WZL: Laravel 13, Blade en Alpine, met een eigen editor op `document.execCommand`. NC: Laravel 12, Inertia en Vue 3, met Tiptap.
- **device-id is het voorbeeld, maar niet één op één.** `device-id` is een engine zonder framework. Dit pakket heeft migraties, modellen, routes en policies nodig en wordt dus een volwaardig Laravel-pakket.
- **NC gebruikt device-id nog niet.** NC heeft een eigen `packages/fingerprint` (`nutricompare/fingerprint`). De overstap staat in de open PR [jw-1980/nutricompare#1896](https://github.com/jw-1980/nutricompare/pull/1896).

## Inventaris per onderdeel

### Productreviews

- **WZL:** algemene score 1–10, vijf vaste criteria als kolommen (smaak, prijs-kwaliteit, structuur, houdbaarheid, gemak), plus- en minpunten, acht tekstvelden, type eenvoudig of uitgebreid, foto's, video-reviews, follow-up "na een maand", vragen onder een review, merkreacties. Eén review per lid per product per type. Model `app/Models/Review.php`, validatie `app/Http/Requests/Reviews/StoreReviewRequest.php`.
- **NC:** score 1–5, titel, plus- en minpunten (json), foto's met moderatie, sentiment via een woordenlijst, merkreactie, maximaal 5 reviews per dag. Eén review per lid per product. Model `app/Models/ProductReview.php`.
- **Samengevoegd:** één reviewmodel met instelbare schaal, optionele uitgebreide velden, foto's en video met moderatie, follow-ups, vragen en merkreacties.

### Beoordelingsonderwerpen

- **WZL:** `rating_topics` en `topic_ratings`: per scope, max. 4 (`RatingScope::MAX_TOPICS`), 1–5 sterren, polymorf via het `Rateable`-contract. Alleen `Additive` (kennisbank) gebruikt het. Admin: `app/Http/Controllers/Admin/RatingTopicController.php`. Daarnaast vragensets per categorie met overerving van de bovenliggende categorie (`app/Services/Reviews/ReviewQuestionnaire.php`).
- **NC:** `review_rating_aspects` en `product_review_aspect_ratings`: globaal, max. 4 (`ReviewRatingAspect::MAX_ACTIVE`), namen in nl en en, 1–5. Het maximum wordt bewaakt met row locks en een retry bij deadlocks (`app/Services/Reviews/ReviewAspectCatalog.php`).
- **Samengevoegd:** onderwerpen per scope en per categorie met overerving, namen in nl en en, instelbaar maximum, aan elk subject te koppelen.

### Schaal

- **WZL:** vast. Reviews 1–10, onderwerpen 1–5, filiaalreviews 1–5, SEO `bestRating` 10.
- **NC:** vast 1–5 op vier plekken (validatie, aspectvalidatie, JSON-LD, migratie).
- **Samengevoegd (nieuw):** schaal 1–5 of 1–10 instelbaar per scope of onderwerp. SEO leidt de schaal af.

### Aggregatie

- **WZL:** `avg_rating` en `review_count` opgeslagen op het product via de job `RefreshProductReviewAggregates` (debounce). Kalibratie van reviewer-bias (`ReviewerBiasModel`). Bayesiaans gemiddelde wel bij ranking, niet bij producten. Wilson-score bestaat (`Comment::helpfulnessRank()`), maar sorteren gebeurt op stemmen voor minus tegen.
- **NC:** live `withAvg` bij elke request, histogram, Bayesiaanse score (`app/Support/Ratings/BayesianRating.php`, berekend maar nergens gebruikt), Wilson-score voor "meest nuttig".
- **Samengevoegd:** opgeslagen aggregaten met een job, Bayesiaanse "betrouwbare score", Wilson-sortering, optionele kalibratie.

### Reacties en threading

- **WZL:** polymorfe engine over 12 secties (`app/Services/Discussions/CommentSection.php`), diepte per sectie (max. 10), bewerktermijn 30 minuten, versiegeschiedenis met woord-diff, placeholder bij verwijderen, platslaan als "↳ antwoord op @naam", topreactie, inklappen bij lage score. Daarnaast een oud, apart pad voor productreacties (`CommentController`).
- **NC:** 3 secties (product, forum, posts), diepte 1–5 per sectie, bewerktermijn 30 minuten hardcoded, revisies, intrekken met tombstone, "bewaard als bewijs" bij een melding. Niet polymorf: harde koppeling aan `product_id` en `product_review_id`.
- **Samengevoegd:** de polymorfe engine van WZL, uitgebreid met "bewaard als bewijs" uit NC, en een instelbare bewerktermijn.

### Editor

- **WZL:** eigen contenteditable met `document.execCommand` (`resources/js/ui/editor.js`), knoppen per sectie, stickers, afbeeldingen, autosave in localStorage en drafts op de server.
- **NC:** Tiptap 3 (StarterKit en Image), emoji-picker, knoppen per sectie, stickers beheerbaar in admin, autosave.
- **Samengevoegd:** Tiptap voor beide apps, knoppen per sectie, stickerbibliotheek in admin, drafts op de server.

### HTML-sanitizing

- **WZL:** `symfony/html-sanitizer` in `app/Services/HtmlSanitizer.php`, allowlist per sectie, `img` alleen voor eigen uploads en stickers.
- **NC:** `ezyang/htmlpurifier` in `app/Services/Comments/CommentHtmlSanitizer.php`, allowlist per sectie, DOMPurify in de browser.
- **Samengevoegd:** `symfony/html-sanitizer` server-side met allowlist per sectie, DOMPurify client-side.

### Stemmen en reacties

- **WZL:** eens/oneens en nuttig/niet nuttig als twee dimensies (`FeedbackKind`), gasten via een HMAC-cookie. Een stem wordt nooit geweigerd maar opgeslagen met `counted=false` en een reden (`AccountLinkService::votePlacement()`). Ongeveer 20 stemtabellen. Emoji-reacties en kudos apart.
- **NC:** eens, oneens, nuttig en *bron nodig* (`app/Enums/CommentVoteType.php`); gasten via een blind index van het netwerk plus het apparaat. Daarnaast overlappend `forum_reactions` (like, inzichtelijk…) en `review_votes`.
- **Samengevoegd:** één stemtabel met dimensies (eens/oneens, nuttig/niet nuttig, bron nodig) en het counted/uncounted-model van WZL. Emoji-reacties blijven apart.

### Moderatie

- **WZL:** meldingen met 5 hardcoded redenen, wachtrijen per type, moderators per filiaal, keten of merk, verborgen fraudecontrole (`FraudReview`), SpamGuard (honeypot, tijdval, links, Akismet), captcha.
- **NC:** meldingen met 6 redenen (waaronder gezondheidsclaim) en een snapshot, een centrale moderatie-inbox, fraudedetectie met 7 signalen, AI-toxiciteitscheck (staat uit), regels voor bijdragen.
- **Samengevoegd:** meldingen met snapshot en instelbare redenen, één inbox, SpamGuard en fraudesignalen als pluggable checks, apparaatrisico via `device-id`.

### Admin-instellingen

- **WZL:** tabel `comment_section_settings` (diepte, tools, feedbacktypes, gasten, sortering, inklappen, topreactie, minimale lengte), onderwerpen, vragensets.
- **NC:** tabel `settings`, groep `comments` per sectie (diepte, rich text, 11 opmaakknoppen, 4 stemtypes), onderwerpen, stickers.
- **Samengevoegd:** instellingen per sectie in een eigen tabel van het pakket. De host-app levert het admin-scherm in de eigen stijl.

### Rechten

- **WZL:** eigen permissiesleutels op `Role`, geregistreerd als Gates, `CommentPolicy`, geen ReviewPolicy.
- **NC:** eigen RBAC met de `permission:`-middleware, policies voor reacties en forum, geen ReviewPolicy.
- **Samengevoegd:** het pakket definieert abilities; de host koppelt die aan de eigen RBAC.

### SEO

- **WZL:** AggregateRating plus maximaal 8 Review-objecten in JSON-LD (`app/Services/Seo/ProductSeo.php`).
- **NC:** alleen AggregateRating; `Schema::review()` wordt nergens aangeroepen.
- **Samengevoegd:** AggregateRating, Review en `DiscussionForumPosting`.

### Vertalingen

- **WZL:** publieke UI hardcoded Nederlands (bewuste keuze, `docs/social-layer-plan.md`).
- **NC:** nl en en, 97 taalbestanden per taal.
- **Samengevoegd:** het pakket levert taalbestanden voor nl en en mee.

### Notificaties

- **WZL:** e-mail bij een antwoord en bij een goedgekeurde review (synchroon, zonder voorkeurcheck), plus meldingen in de app.
- **NC:** volgers van nieuwe reviews en reacties, forum-antwoorden. Geen melding bij een antwoord op je reactie.
- **Samengevoegd:** domain events; de host bepaalt de kanalen en voorkeuren.

## Openstaande to-do's over dit systeem

Letterlijk teruggevonden in code of documentatie. In geen van beide repositories staan open GitHub-issues of open PR's over reviews of reacties.

### WelZoLekker

- **Ontwerp mobiele draad nooit afgemaakt.** `docs/design/stitch/briefs/22-draad-mobiel-parts.txt`: inspringen maximaal 3 niveaus, compacte feedbackknoppen, reply-composer als bottom sheet. Het "↳ antwoord op @naam"-deel bestaat al; de bottom sheet is niet geverifieerd.
- **Admin-ontwerp reacties niet gegenereerd.** `docs/design/stitch/briefs/23-admin-reacties-parts.txt`: de functionaliteit bestaat, alleen het Stitch-ontwerp ontbreekt.
- **Al gepubliceerde bijdragen van later gemarkeerde accounts blijven staan.** `README.md` regels 173–175, "Niet gedaan". Ook harde weigeringen van de spamfilter zijn zichtbaar voor de gebruiker. Na 48 uur sluit alleen het apparaat-item, niet het account.
- **DSA artikel 17, juridisch open punt (HIGH).** `README.md` regels 177–186: de stille wachttijd tijdens `pending` is mogelijk een zichtbaarheidsbeperking waarvoor een motivering verplicht is. "Laat dit toetsen door een jurist voordat de app live gaat."
- **Race condition bij gaststem op het Smaakduel.** `README.md` rond regel 220: geen row lock, dus twee gelijktijdige stemmen met dezelfde hash kunnen allebei doorgaan.
- **Openstaande punten bedrijfsaccounts** (`docs/business-accounts/STATUS.md`):
  - B1-49: moderators kunnen een bericht niet achteraf als reclame markeren.
  - B2-41: een melder ziet geen overzicht van de eigen klachten, en er is geen geschillencommissie.
  - B1-41 / B1-43: officiële antwoorden en pins zijn er alleen bij ideeën, vragen, prikbord en merkupdates.
  - F6 (SEO-review): niet gebouwd.
- **Tegenstrijdigheid.** `docs/social-layer-plan.md` zegt "Social e-mail: none", maar `CommentReplyNotification` en `ReviewApprovedNotification` gaan alleen per e-mail. Ook nog open: "No English translation" (§7.9 punt 11).

### NutriCompare

- **Reacties polymorf maken en reviewcriteria per vertical.** `docs/design/PRODUCT_AGNOSTIC_PLATFORM_FUNCTIONAL_DESIGN.md` §8.4/§8.5, V14/V15:
  - reviewcriteria per vertical (geur, textuur, smaak);
  - reacties polymorf (product, review, merk, artikel, ingrediënt);
  - een sentiment-lexicon per taal en per vertical;
  - als verpakkingsvorm: interne Composer-pakketten, met `packages/fingerprint` als voorbeeld.
- **Classifier op bestaande moderatielabels.** `docs/design/SOCIAL_LAYER_PLAN.md` §2.4: de score van de classifier bepaalt de prioriteit in de wachtrij en verwijdert nooit automatisch. Een LLM in het moderatiepad staat bij "What I would not build".
- **Follow-ups in code:**
  - `app/Services/Comments/PostCommentService.php:30-31`: geen fan-out naar volgers en geen melding aan de auteur van de post.
  - `app/Http/Controllers/User/ProductController.php:163-168`: de Bayesiaanse score wordt nergens gebruikt.
  - `app/Support/Contributions/ContributionItemRegistry.php:414-415`: "Reviews have no member edit or delete route today".
  - `app/Models/ProductReview.php:382-388`: `report_count` wordt nooit opgehoogd.
- **Mobiele API voor reviews.** `docs/design/MOBILE_COMPANION_APP.md` §6.4/§7.3: `GET`/`POST /api/v1/products/{id}/reviews`, scope `reviews:write`, en indienen via de governance-wachtrij.
- **Roadmap-ideeën die nog niet gebouwd zijn** (`docs/FEATURE_ROADMAP_PART1.md` t/m `PART3.md`): filteren op gezondheidsdoel of leeftijdsgroep, beoordeling van effectiviteit en bijwerkingen, Review Sentiment Analyzer, Adverse Reaction Tracking, Retailer Rating Integration, Brand Quality Scoring, minimale score als filter, Product Reviews API, Report Annotations. Expliciet *niet* doen (`docs/design/functional-design-2026-09/GAP_ANALYSIS.md` D-10): "verified buyer" en het importeren van reviews.
- **Settings die niets doen.** `community.enable_review_voting` en `features.enable_top_reviewers` staan in `app/Models/Setting.php`, maar worden nergens uitgelezen.
- **Dossier mist het reactiesysteem.** In `docs/research/NUTRICOMPARE_PROJECT_AND_DOMAIN_KNOWLEDGE.md` staat niets over reactiestemmen, stickers, onderwerpen of de sanitizer.

## Gevonden gaten en bugs

Status van alles hieronder: **REPORTED**. Er is nog niets aangepast. De ernst is een inschatting op basis van de code.

### WelZoLekker

| Ernst | Bevinding | Bewijs |
|---|---|---|
| HIGH | Een review die een moderator heeft afgewezen, gaat weer direct naar *approved* als een vertrouwd lid hem opnieuw indient. | `ReviewController::store()` regel 102 zet de status alleen op basis van SpamGuard. |
| MEDIUM | Het aparte pad voor productreacties mist de sanitizer per sectie, de controle op geblokkeerde gebruikers en een spamcheck bij bewerken. Als de auteur een reactie verwijdert, verdwijnen ook de antwoorden van anderen. N+1-query vanaf niveau 3, geen paginering. | `CommentController::store`/`update`/`destroy`, `ProductController.php:77-88`. |
| MEDIUM | "Nuttig" bij een review heeft geen rate limit; alle andere stemroutes wel. | `routes/web.php:474`. |
| MEDIUM | E-mail bij een antwoord en bij een goedgekeurde review wordt synchroon verstuurd, zonder voorkeurcheck. | `CommentReplyNotification`, `ReviewApprovedNotification`: `['mail']`, geen ShouldQueue. |
| LOW | Leden kunnen hun eigen review niet verwijderen. | Geen `reviews.destroy`-route. |
| LOW | `is_verified_purchase` wordt nooit geschreven. | `reviews`-tabel. |
| LOW | `community.reviews_per_page` wordt niet gebruikt; vast 8 reviews, geen sortering op nuttig. | `ProductController.php:53-59`. |
| LOW | Reviewmoderatie heeft geen bulkactie; `reports` heeft geen handled_by/handled_at; geen automatisch verbergen bij veel meldingen. | `Admin/ReviewModerationController.php`, migratie `reports`. |
| LOW | `SpamGuard::duplicate()` vergelijkt maar een deel van de reviewtekst. | `app/Services/SpamGuard.php`. |
| LOW | De samenvatting van onderwerp-ratings wordt niet gecachet. | `TopicRatingService::summary()`. |
| LOW | `device-id` staat vast op de branch `dev-ccr-fb5c87df-ly728e` in plaats van een tag. | `composer.json:10`. |

### NutriCompare

| Ernst | Bevinding | Bewijs |
|---|---|---|
| MEDIUM | Je kunt "nuttig" stemmen op je eigen review; alleen de punten worden overgeslagen. Bij reacties wordt een stem op je eigen bericht wel geweigerd. | `ReviewController.php:879`. |
| MEDIUM | Productreviews, productreacties en reacties op posts krijgen geen check op toxiciteit, Akismet of gezondheidsclaims. Productreacties hebben geen device-risk-middleware; forumposts en reactiestemmen worden niet op fraude gescand. | `FraudDetectionService`, `ForumService.php:272-276`. |
| MEDIUM | Admin → Reviews staat niet in de navigatie, en reviews die op goedkeuring wachten verschijnen niet in de moderatie-inbox. | `config/admin-navigation.php:205-225`. |
| MEDIUM | Geen morph map: polymorfe kolommen bevatten volledige klassenamen. Als modellen naar een pakket verhuizen, kloppen de opgeslagen `*_type`-waarden niet meer. Speelt ook bij WZL. | Geen `morphMap`/`enforceMorphMap` in de code. |
| LOW | Dode kolommen `helpful_count` en `report_count`. | `ProductReview.php:382-388`. |
| LOW | Drie overlappende stemsystemen: `comment_votes`, `forum_reactions`, `review_votes`. | Migraties. |
| LOW | Geen melding bij een antwoord op je reactie, en geen melding aan de melder over de afhandeling. | `PostCommentService.php:30-31`. |
| LOW | `Schema::review()` wordt niet gebruikt; stackreviews zijn dode code; merkreacties zijn niet te melden. | `app/Support/Seo/Schema.php:708-728`, `StackBuilderService::addReview()`, `ContentReportController.php:113-149`. |

## Aanbevelingen voor het pakket

1. **Een Laravel-pakket, niet framework-loos zoals device-id.** Een eigen ServiceProvider met auto-discovery, publiceerbare migraties en config, taalbestanden voor nl en en, en contracts (`Commentable`, `Rateable`, `Moderatable`, `VoterIdentity`, `RiskSignals`). Het domein heeft modellen, routes en policies nodig; een pure engine zou die in beide apps weer dubbel laten bouwen.
2. **Frontend als twee lagen (gekozen).** Het PHP-pakket levert een JSON-API en Blade-componenten voor WZL. Een npm-pakket levert Vue 3-componenten voor NC. Beide gebruiken dezelfde Tiptap-configuratie.
3. **Tiptap voor beide (gekozen).** `document.execCommand` is een verouderde browser-API. Tiptap is volwassen, werkt ook zonder Vue en is al in gebruik bij NC. De knopset per sectie van beide apps gaat één op één over.
4. **Eén server-side sanitizer (gekozen).** `symfony/html-sanitizer` met de allowlist per sectie uit beide apps; DOMPurify in de browser. HTMLPurifier vervalt in NC.
5. **Eerst een morph map, dan pas extraheren.** Voer in beide apps `Relation::enforceMorphMap()` in, met een idempotente datamigratie die de `*_type`-kolommen omzet naar korte aliassen. Zo kunnen modelklassen verhuizen zonder dat er data stukgaat.
6. **Eén stemtabel met dimensies.** Het WZL-model: een stem wordt nooit geweigerd maar opgeslagen met `counted` en `uncounted_reason`, aangevuld met de dimensie "bron nodig" uit NC.
7. **Sla de schaal op bij de rating.** Ruwe waarde plus `scale_max` (5 of 10) per onderwerp of scope; aggregaten worden genormaliseerd berekend. Oude scores blijven kloppen als een admin de schaal wijzigt, en `bestRating` in SEO klopt vanzelf.
8. **Koppel aan device-id via een interface.** Het pakket vraagt alleen "is deze stem of bijdrage telbaar?" via een `RiskSignals`-contract. Beide apps implementeren dat met `device-id`; NC zodra [jw-1980/nutricompare#1896](https://github.com/jw-1980/nutricompare/pull/1896) gemerged is.
9. **Domain events in plaats van inline bijwerkingen.** `ReviewPublished`, `CommentPosted`, `VoteCast`, `ContentReported`, `ModerationDecided`. Punten, badges, notificaties en volgers blijven in de host-app.
10. **Semver-tags in plaats van een dev-branch.** Tag het pakket (bijvoorbeeld `v0.1.0`) en gebruik `^0.1` in beide apps. Doe dat ook voor `device-id`.
11. **Migratiepad in vier stappen:**
    1. Repareer de HIGH- en MEDIUM-punten en voer de morph map in, per app.
    2. Maak het pakket met de engine van WZL als basis (de meest volledige) en de onderwerpen in nl en en uit NC.
    3. WZL over (Blade-adapter).
    4. NC over (Vue-adapter), met een datamigratie van `product_comments` naar polymorf.

    NC-tests draaien op MySQL 8.4, zonder SQLite en zonder Docker (NC `CLAUDE.md`, hoofdstuk 7).

## 25 nieuwe features

Gecontroleerd tegen de code en documentatie van beide repositories: deze bestaan nog niet en staan nergens genoteerd. Twee kandidaten zijn geschrapt omdat ze al deels bestaan: reviews koppelen aan een receptversie (WZL heeft `RecipeChangeScore`) en bezwaar tegen een moderatiebesluit (WZL heeft al bezwaar bij bedrijfssancties).

1. **AI-samenvatting van reviews.** Plus- en minpunten per onderwerp in drie zinnen, met een link naar de reviews waarop elke zin gebaseerd is. Bij voorkeur een lokaal model.
2. **TL;DR van lange discussies.** Vanaf een instelbaar aantal reacties een samenvatting met de belangrijkste standpunten en de verhouding eens/oneens.
3. **Zoeken binnen reviews en discussies.** Full-text zoeken per product ("lactose", "smaak na opwarmen"), met de treffers gemarkeerd.
4. **Prijs op het moment van de review.** "Prijs toen: € 1,89" bij elke review, uit de prijshistorie. Een prijs-kwaliteitoordeel krijgt zo context.
5. **"Vergeleken met…"** De reviewer koppelt een alternatief dat beter of slechter is. Geaggregeerd: "reviewers stappen vaak over op X".
6. **Kwaliteitsmeter tijdens het schrijven.** Live hints, zoals "voeg een minpunt toe" of "noem hoe je het gebruikt", met een meter van zwak naar sterk.
7. **Review-uitnodiging na aankoop.** Een aantal dagen na een kassabonscan of afgevinkt boodschappenlijstje (WZL), of na "in gebruik genomen" (NC): een uitnodiging om een review te schrijven.
8. **Context-facetten per review.** "Eerste keer gekocht", "Vaste koper", "In de aanbieding". Filterbaar en meegenomen in de samenvatting.
9. **Onzekerheidsband bij de score.** "8,1 ± 0,4", of "nog weinig reviews", in plaats van alleen een getal.
10. **Scoreverloop in de tijd.** Gemiddelde per maand of kwartaal als sparkline.
11. **Sorteren op "Controversieel".** Reacties met veel eens- én oneens-stemmen bovenaan.
12. **Citeer-selectie.** Selecteer tekst in een reactie en kies "Citeer en reageer". Het citaat linkt terug naar de bron.
13. **Productkaart in de editor.** Typ `#` plus een productnaam om een interne productkaart met prijs of score in te voegen.
14. **Mini-peiling in een reactie.** Een keuzevraag met 2–4 opties, met dezelfde regel van één stem per persoon.
15. **"Nieuw sinds je laatste bezoek".** Een markering en een knop "spring naar eerste nieuwe" in discussies die je eerder hebt gelezen.
16. **Slow mode per discussie.** Een moderator stelt in dat iemand maximaal één reactie per X minuten plaatst.
17. **Vertrouwensniveaus ontgrendelen tools.** Links, afbeeldingen of diepe antwoorden pas na N goedgekeurde bijdragen, per sectie in te stellen.
18. **Herformuleer-suggestie.** Een deterministische woordenlijst ziet een vijandige toon en vraagt "Wil je dit anders verwoorden?". Plaatsen kan toch. Geen AI in het pad.
19. **Workflow voor "bron nodig".** Vanaf een instelbaar aantal "bron nodig"-stemmen krijgt de auteur een verzoek om een bron toe te voegen. Die verschijnt als bronchip; het label verdwijnt daarna.
20. **Alt-tekst bij afbeeldingen.** Een beschrijving bij elke geüploade afbeelding in reacties en reviews, voor toegankelijkheid.
21. **Profiel-hovercard.** Hover of tik op een naam voor een kaartje met het aantal reviews, badges, "lid sinds" en "volgen".
22. **Openbaar moderatielogboek.** Onderaan de draad: "3 reacties verwijderd: 2× spam, 1× persoonsgegevens", zonder inhoud.
23. **Ongedaan maken na plaatsen.** Tien seconden "Ongedaan maken" voordat een reactie gepubliceerd wordt en meldingen uitgaan.
24. **Foto-annotaties.** De reviewer zet een markering op de foto ("schimmel hier", "lege ruimte in verpakking").
25. **"Wil ik proberen" vanuit een review.** Eén tik zet het product op de boodschappenlijst (WZL) of de verlanglijst (NC), met de bron-review erbij.

## Stitch-ontwerpen

Per app één productpagina met de samengevoegde functies, in de bestaande huisstijl:

- **WelZoLekker** ("Warm Premium", framboos, Geist): [productpagina met reviews en discussies](https://stitch.withgoogle.com/projects/5997425675918305643). Score 1–10, 4 onderwerpen, histogram, reviewlijst met merkreactie, threaded discussie met eens/oneens/nuttig/bron nodig en de editor.
- **NutriCompare** ("Clinical Integrity", teal, Plus Jakarta Sans): [productpagina met reviews en discussies](https://stitch.withgoogle.com/projects/17328506312512873324). Score 1–5 met een gewogen score, 4 onderwerpen, reviewlijst, discussie met compacte stemknoppen en een tombstone.
