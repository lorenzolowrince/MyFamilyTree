/** Shapes sent by the family, tree and member controllers. */

export type FamilyRef = {
    slug: string;
    name: string;
};

export type CurrentFamily = FamilyRef & {
    role: string;
    /** Proposals waiting for this person to review. */
    pendingChanges: number;
    /** A hint for showing or hiding buttons. The server authorises every request. */
    permissions: string[];
};

export type FamilySummary = FamilyRef & {
    description: string | null;
    role: string | null;
    members: number;
    generations: number;
    cover_url: string | null;
};

/** A person as shown on cards, lists and pickers (MemberPresenter::summary). */
export type MemberSummary = {
    id: string;
    name: string;
    nickname: string | null;
    gender: 'male' | 'female' | 'other' | 'unknown' | null;
    living: boolean;
    living_status: 'living' | 'deceased' | 'unknown';
    lifespan: string | null;
    birth_year: number | null;
    is_placeholder: boolean;
    generation: number | null;
    visibility: 'family' | 'restricted' | 'private' | null;
    is_me: boolean;
    /** True when the viewer may not see this person: only a "Private relative" card is shown. */
    hidden: boolean;
};

export type FuzzyDateValue = {
    year: number | null;
    month: number | null;
    day: number | null;
    qualifier: string;
    year_to: number | null;
    decade: boolean;
    text: string | null;
    label?: string | null;
};

export const emptyFuzzyDate: FuzzyDateValue = {
    year: null,
    month: null,
    day: null,
    qualifier: 'exact',
    year_to: null,
    decade: false,
    text: null,
};

export type MemberProfile = MemberSummary & {
    given_names: string | null;
    surname: string | null;
    maiden_name: string | null;
    sensitive_hidden: boolean;
    birth: FuzzyDateValue | null;
    birth_place: string | null;
    death: FuzzyDateValue;
    death_place: string | null;
    hometown: string | null;
    occupation: string | null;
    education: string | null;
    nationality: string | null;
    biography: string | null;
    notes: string | null;
    version: number;
    updated_at: string | null;
};

export type Option = { value: string; label: string };

export type GenealogyOptions = {
    genders: Option[];
    livingStatuses: Option[];
    qualifiers: Option[];
    certainties: Option[];
    visibilities: Option[];
    parentSubtypes: Option[];
    partnershipSubtypes: Option[];
    partnershipStatuses: Option[];
};

export type RelativeKind = 'parent' | 'child' | 'partner' | 'sibling';

export type RelationshipInfo = {
    id: string;
    type: 'parent_child' | 'partnership';
    subtype: string;
    certainty?: string | null;
    status?: string | null;
    start?: FuzzyDateValue | null;
    end?: FuzzyDateValue | null;
    place?: string | null;
    notes?: string | null;
};

export type Relatives = {
    parents: { relationship: RelationshipInfo; person: MemberSummary }[];
    partners: { relationship: RelationshipInfo; person: MemberSummary }[];
    children: {
        relationship: RelationshipInfo;
        person: MemberSummary;
        other_parents: MemberSummary[];
    }[];
    siblings: {
        person: MemberSummary;
        kind: 'full' | 'half' | 'by_family' | 'sibling';
    }[];
};

// --- Tree slice (TreeSliceBuilder) -------------------------------------------------

export type TreeUnion = {
    partner: string | null;
    relationship: { id: string; subtype: string; status: string | null } | null;
    children: TreeUnit[];
};

/** A person with their partners and, under each couple, their children. */
export type TreeUnit = {
    person: string;
    /** How this person is linked to the parent above (biological, adoptive...). */
    link: string | null;
    unions: TreeUnion[];
    /** There are children that were not loaded (depth limit). */
    more: boolean;
    /** This person already appears elsewhere in the picture. */
    repeat: boolean;
};

export type TreeAncestor = {
    person: string;
    link: string | null;
    parents: TreeAncestor[];
    more: boolean;
    repeat: boolean;
};

export type TreeSlice = {
    focus: string;
    descendants: TreeUnit;
    ancestors: TreeAncestor;
};

export type TreeView = 'hourglass' | 'descendants' | 'ancestors';

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

// --- History (timeline, events, dashboard) -----------------------------------------

export type TimelineItem = {
    id: string;
    /** `entry` is a recorded entry; the others are taken from people and marriages. */
    kind: 'entry' | 'birth' | 'death' | 'marriage';
    category: string;
    title: string;
    description: string | null;
    date: FuzzyDateValue;
    sort: string;
    year: number | null;
    place: string | null;
    visibility: string | null;
    people: MemberSummary[];
    editable: boolean;
};

export type MemberNameItem = {
    id: string;
    name: string;
    type: string;
    type_label: string;
    notes: string | null;
};

export type FamilyEventItem = {
    id: string;
    title: string;
    type: string;
    type_label: string;
    description: string | null;
    starts_at: string;
    ends_at: string | null;
    starts_local: string;
    ends_local: string | null;
    all_day: boolean;
    location_text: string | null;
    notify_members: boolean;
    happened: boolean;
    on_timeline: boolean;
};

export type FamilyOverview = {
    family: FamilyRef;
    stats: {
        members: number;
        living: number;
        deceased: number;
        generations: number;
        relationships: number;
    };
    recent: MemberSummary[];
    birthdays: {
        person: MemberSummary;
        date: string;
        days: number;
        turning: number;
    }[];
    anniversaries: {
        people: MemberSummary[];
        date: string;
        days: number;
        years: number;
    }[];
    events: {
        id: string;
        title: string;
        type: string;
        starts_at: string;
        all_day: boolean;
        location: string | null;
    }[];
    /** Null when this person may not read the activity feed. */
    activity:
        | {
              id: string;
              summary: string;
              actor: string | null;
              created_at: string;
          }[]
        | null;
};

// --- Collaboration (people, proposals, history) ------------------------------------

export type FamilyPerson = {
    id: string;
    name: string | null;
    email: string | null;
    role: string;
    role_label: string;
    status: string;
    joined_at: string | null;
    linked_member: { id: string; name: string | null } | null;
    is_me: boolean;
    can: { manage: boolean; link: boolean };
};

export type InvitationItem = {
    id: string;
    email: string;
    role_label: string;
    state: 'pending' | 'accepted' | 'revoked' | 'expired';
    expires_at: string;
    created_at: string | null;
};

export type ChangeItem = {
    id: string;
    kind: string;
    summary: string;
    status: 'pending' | 'approved' | 'rejected' | 'withdrawn' | 'superseded';
    submitted_by: string;
    is_mine: boolean;
    created_at: string | null;
    reviewer: string | null;
    reviewed_at: string | null;
    review_note: string | null;
    /** Field by field: the current value and the proposed one. */
    rows: { label: string; old: string | null; new: string | null }[];
    /** The record was changed after this proposal was made. */
    conflict: boolean;
    /** The record this proposal is about no longer exists. */
    missing: boolean;
    target_id: string | null;
    can: { review: boolean; withdraw: boolean };
};

export type ChangeModeValue = 'direct' | 'propose' | 'deny';
