# D & E. Application Structure

## D. Laravel backend structure

Standard Laravel layout (so any Laravel developer can find their way), plus three conventions: **Actions** for business logic, **Policies** for every authorisation decision, and **Resources** for every outbound data shape.

```
app/
├── Actions/                    # One class per business operation, used by web AND future API
│   ├── Families/               # CreateFamily, TransferOwnership, DeleteFamily
│   ├── Members/                # CreateMember, UpdateMember, MergeMembers, DeleteMember
│   ├── Relationships/          # AddParent, AddChild, AddPartner, ConnectSiblings, RemoveRelationship
│   ├── Collaboration/          # InviteMember, AcceptInvitation, SubmitChange, ApproveChange, RejectChange
│   ├── Media/                  # StorePhoto, StoreDocument, MovePhoto, DeleteMedia
│   └── Account/                # ExportMyData, DeleteMyAccount, DeactivateAccount
├── Enums/                      # RelationshipType, ParentSubtype, DatePrecision, FamilyRole, Visibility...
├── Events/ & Listeners/        # MemberCreated → notify, bump graph_version, write activity log
├── Exceptions/                 # RelationshipCycleException, VersionConflictException...
├── Http/
│   ├── Controllers/
│   │   ├── Web/                # Inertia controllers (thin: validate → authorise → Action → render)
│   │   ├── Api/V1/             # Future native-app JSON API (Sanctum), same Actions
│   │   └── Admin/              # Super-admin dashboard
│   ├── Middleware/             # ResolveCurrentFamily, EnsureFamilyMember, SecurityHeaders
│   ├── Requests/               # Form Request validation per action
│   └── Resources/              # MemberResource etc.: decide which fields each viewer receives
├── Jobs/                       # ProcessUploadedPhoto, ScanFileForMalware, RecomputeGenerations,
│                               # GenerateReportPdf, ParseGedcom, ImportGedcom, SendBirthdayReminders
├── Models/                     # Eloquent models + BelongsToFamily trait (global scope)
├── Notifications/              # Mail + WebPush channels
├── Policies/                   # FamilyPolicy, MemberPolicy, PhotoPolicy, ChangeRequestPolicy...
├── Services/
│   ├── Genealogy/              # FamilyGraph, RelationshipCalculator, GenerationCalculator,
│   │                           # DuplicateDetector, TreeSliceBuilder
│   ├── Gedcom/                 # Parser, Validator, Exporter, Mapper (GEDCOM 7.0)
│   ├── Media/                  # ImageProcessor (strip EXIF/GPS, resize), MalwareScanner (ClamAV)
│   ├── Privacy/                # LivingPersonPolicy, FieldMasker
│   └── Reports/                # PDF report builders
└── Support/
    └── FuzzyDate/              # FuzzyDate value object, Eloquent cast, GEDCOM date parser/formatter
database/
├── migrations/
├── seeders/                    # RolesAndPermissionsSeeder (prod), DemoFamilySeeder (non-prod)
└── factories/
resources/js/                   # Frontend (see E)
routes/
├── web.php                     # Inertia routes, grouped under /families/{family}
├── api.php                     # /api/v1 (added when native apps start)
├── admin.php
└── console.php                 # Scheduled tasks
tests/
├── Unit/                       # RelationshipCalculator, FuzzyDate, GEDCOM parser
├── Feature/                    # HTTP + authorisation (every route has a "wrong family" test)
└── Browser/                    # Critical journeys (Pest browser testing / Playwright)
docs/
├── architecture/               # These documents
└── adr/                        # Architecture Decision Records, one per significant decision
```

### Why Actions + Policies + Resources

- **Actions** hold the business logic once. Web controllers today and API controllers tomorrow call the same `AddParent` action, so the rules (cycle checks, approval workflow, audit) cannot differ between web and mobile.
- **Policies** make authorisation explicit and testable. A controller method without `$this->authorize(...)` fails a CI check (an architecture test).
- **Resources** are the only way data leaves the server. `MemberResource` asks the privacy service which fields this viewer may see, so a private date of birth never reaches the browser, even hidden in page props.

### Tenant scoping

A `BelongsToFamily` trait adds a global scope that limits queries to the family resolved from the route. Route model binding is **scoped** (`/families/{family}/members/{member}` only resolves a member that belongs to that family), and the composite FKs in the database back it up. Three layers, so one mistake does not leak data.

### Key libraries

| Need                                          | Library                                                  | Notes                                                                                                                  |
| --------------------------------------------- | -------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------- |
| Auth, MFA, password reset, email verification | **Laravel Fortify** (via the official React starter kit) | First-party. TOTP MFA + recovery codes.                                                                                |
| Social login                                  | **Laravel Socialite**                                    | Google now; Apple when the developer account exists.                                                                   |
| API tokens (future native apps)               | **Laravel Sanctum**                                      | First-party.                                                                                                           |
| Queues dashboard                              | **Laravel Horizon**                                      | First-party.                                                                                                           |
| Monitoring                                    | **Laravel Pulse**                                        | First-party; self-hosted, no data leaves the server.                                                                   |
| Web push                                      | `laravel-notification-channels/webpush`                  | VAPID; maintained community package.                                                                                   |
| Images                                        | `intervention/image` (Imagick driver)                    | Resize, strip metadata, HEIC → JPEG.                                                                                   |
| PDF                                           | `spatie/laravel-pdf` + Browsershot (Chromium)            | Renders the actual D3 SVG tree. Alternative if Chromium is rejected: dompdf (text reports only; tree as static image). |
| Rich text sanitising                          | `mews/purifier` (HTML Purifier)                          | Biographies are user HTML.                                                                                             |
| Static analysis / style                       | Larastan, Pint, Rector                                   | Dev only.                                                                                                              |
| Tests                                         | Pest                                                     | Dev only.                                                                                                              |

Deliberately **not** used: a generic RBAC package (Spatie Permission). Family-scoped roles with a fixed matrix are simpler as our own three tables plus Policies, with one less dependency. GEDCOM: no well-maintained PHP library supports GEDCOM 7, so we write a focused parser/exporter with conformance tests against the official FamilySearch sample files.

---

## E. Frontend component architecture

React + TypeScript in `resources/js`, rendered through Inertia.

```
resources/js/
├── app.tsx                     # Inertia bootstrap, theme, i18n
├── pages/                      # One file per Inertia page (route)
│   ├── Dashboard.tsx
│   ├── Families/               # Index, Create, Settings
│   ├── Tree/Show.tsx           # The family tree (central feature)
│   ├── Members/                # Index (search), Show (profile), Edit
│   ├── Timeline/Index.tsx
│   ├── Gallery/                # Albums, Album, Photo
│   ├── Documents/Index.tsx
│   ├── Events/Index.tsx
│   ├── Invitations/Index.tsx
│   ├── Changes/Index.tsx       # Review queue
│   ├── Reports/Index.tsx
│   ├── RelationshipFinder.tsx
│   ├── Settings/               # Profile, Security (MFA, sessions), Notifications, Data export
│   ├── Admin/                  # Super-admin pages
│   └── Offline/Viewer.tsx      # Offline read-only viewer (see PWA)
├── layouts/
│   ├── AppLayout.tsx           # Sidebar (desktop) / bottom nav (mobile), family switcher
│   ├── AuthLayout.tsx
│   └── AdminLayout.tsx
├── components/
│   ├── ui/                     # Design system primitives (shadcn/ui on Radix): Button, Dialog,
│   │                           # Input, Select, Sheet, Tabs, Toast, DataTable, EmptyState...
│   ├── tree/                   # FamilyTreeCanvas (D3), MemberCard, CoupleNode, TreeControls,
│   │                           # TreeMinimap, PathHighlighter, TreeListView (accessible alternative)
│   ├── members/                # MemberAvatar, MemberPicker (search + duplicate check),
│   │                           # FuzzyDateInput, PlaceInput, RelationshipBadge, AddRelativeDialog
│   ├── media/                  # Uploader (camera capture, progress, resumable), PhotoGrid,
│   │                           # Lightbox, DocumentPreview
│   ├── timeline/               # TimelineList, TimelineEntry
│   ├── collaboration/          # ChangeDiff (side-by-side old/new), ApprovalActions
│   └── dashboard/              # StatTile, UpcomingBirthdays, ActivityFeed, TreeOverview
├── hooks/                      # useFamily, usePermissions, useOnlineStatus, useTreeData...
├── lib/                        # api helpers, fuzzy-date formatting, relationship labels, i18n
├── pwa/                        # Service worker registration, IndexedDB store, sync outbox
└── types/                      # Generated TS types from PHP Resources/enums
```

### Key frontend decisions

- **shadcn/ui on Radix primitives + Tailwind.** Accessible components (focus management, keyboard support, ARIA) that we own as source code rather than as a black-box dependency. This is what the official Laravel React starter kit uses.
- **D3 owns only the tree canvas.** React renders everything else. D3 computes the layout and handles zoom/pan inside one `<svg>` that React does not touch, which avoids the two libraries fighting over the DOM. Member cards inside the tree are SVG `<foreignObject>`/React-portal hybrids so they share styling with the rest of the app.
- **Typed end to end.** PHP enums and Resource shapes are exported to TypeScript types (via `spatie/laravel-typescript-transformer` or Wayfinder for routes), so a renamed backend field breaks the frontend build instead of production.
- **Permissions in the UI are hints, not security.** `usePermissions()` hides buttons the user cannot use; the server still checks every request.
- **Theming:** light and dark themes via CSS variables, following the OS setting with a manual override.
- **Mobile-first:** bottom navigation on phones, sidebar on desktop; the tree supports pinch zoom and has a "list" mode for small screens.
- **Accessibility target:** WCAG 2.2 AA.
