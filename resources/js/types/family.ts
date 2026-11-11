/** Shapes sent by the family, tree and member controllers. */

export type FamilyRef = {
    slug: string;
    name: string;
};

export type CurrentFamily = FamilyRef & {
    role: string;
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
