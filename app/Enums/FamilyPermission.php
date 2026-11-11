<?php

namespace App\Enums;

/**
 * Family-level permissions. Together with `roles()` this is the matrix in
 * docs/architecture/05-roles-permissions.md, seeded into `family_permissions` and
 * `family_role_permissions`.
 *
 * Rules that sit on top of the matrix (own linked profile, record visibility, living-person
 * privacy, "only roles below your own") are applied by Policies, not stored here.
 */
enum FamilyPermission: string
{
    // Tree
    case TreeView = 'tree.view';
    case MembersViewLivingSensitive = 'members.view_living_sensitive';
    case MembersViewRestricted = 'members.view_restricted';
    case MembersViewPrivate = 'members.view_private';
    case MembersCreate = 'members.create';
    case MembersUpdate = 'members.update';
    case MembersDelete = 'members.delete';
    case RelationshipsManage = 'relationships.manage';
    case TimelineCreate = 'timeline.create';

    // Media
    case MediaView = 'media.view';
    case MediaUpload = 'media.upload';
    case MediaEditAny = 'media.edit_any';
    case MediaDeleteAny = 'media.delete_any';
    case DocumentsDownloadAny = 'documents.download_any';

    // Collaboration
    case ChangesPropose = 'changes.propose';
    case ChangesReview = 'changes.review';
    case MembersInvite = 'members.invite';
    case RolesAssign = 'roles.assign';
    case MembersRemove = 'members.remove';

    // Reports and data
    case ReportsGenerate = 'reports.generate';
    case ReportsIncludeRestricted = 'reports.include_restricted';
    case GedcomExport = 'gedcom.export';
    case GedcomImport = 'gedcom.import';
    case AuditView = 'audit.view';
    case AuditViewRecords = 'audit.view_records';

    // Family settings
    case FamilyUpdate = 'family.update';
    case FamilyGrantSupportAccess = 'family.grant_support_access';
    case FamilyTransferOwnership = 'family.transfer_ownership';
    case FamilyDelete = 'family.delete';

    /** Primary key in `family_permissions`: position in this list. Append new cases at the end of the enum. */
    public function id(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    public function description(): string
    {
        return match ($this) {
            self::TreeView => 'View the tree and deceased members\' profiles; living members\' basic info',
            self::MembersViewLivingSensitive => 'View living members\' exact dates, places and contact notes',
            self::MembersViewRestricted => 'View records marked restricted',
            self::MembersViewPrivate => 'View records marked private',
            self::MembersCreate => 'Add members directly',
            self::MembersUpdate => 'Edit members directly',
            self::MembersDelete => 'Delete or merge members directly',
            self::RelationshipsManage => 'Add, edit and remove relationships directly',
            self::TimelineCreate => 'Add timeline entries directly',
            self::MediaView => 'View photos and documents (per file visibility)',
            self::MediaUpload => 'Upload photos and documents',
            self::MediaEditAny => 'Edit captions, tags and albums of any file',
            self::MediaDeleteAny => 'Delete any photo or document',
            self::DocumentsDownloadAny => 'Download documents even when not marked downloadable',
            self::ChangesPropose => 'Submit changes for approval',
            self::ChangesReview => 'Review and approve change requests',
            self::MembersInvite => 'Invite people to the family',
            self::RolesAssign => 'Change the roles of people in the family (below your own role)',
            self::MembersRemove => 'Remove people from the family (below your own role)',
            self::ReportsGenerate => 'Generate PDF reports',
            self::ReportsIncludeRestricted => 'Include restricted data in reports',
            self::GedcomExport => 'Export the tree as GEDCOM',
            self::GedcomImport => 'Import a GEDCOM file',
            self::AuditView => 'View the family\'s full audit history',
            self::AuditViewRecords => 'View the change history of individual records',
            self::FamilyUpdate => 'Edit name, cover, privacy defaults and approval policy',
            self::FamilyGrantSupportAccess => 'Grant time-limited support access to platform staff',
            self::FamilyTransferOwnership => 'Transfer ownership of the family',
            self::FamilyDelete => 'Delete the family',
        };
    }

    /**
     * Roles that hold this permission.
     *
     * @return list<FamilyRole>
     */
    public function roles(): array
    {
        $owner = [FamilyRole::Owner];
        $admins = [FamilyRole::Owner, FamilyRole::Admin];
        $editors = [...$admins, FamilyRole::Editor];
        $contributors = [...$editors, FamilyRole::Contributor];
        $everyone = [...$contributors, FamilyRole::Viewer];

        return match ($this) {
            self::TreeView,
            self::MediaView => $everyone,

            self::MediaUpload,
            self::ReportsGenerate => $contributors,

            self::MembersViewLivingSensitive,
            self::MembersViewRestricted,
            self::MembersCreate,
            self::MembersUpdate,
            self::RelationshipsManage,
            self::TimelineCreate,
            self::MediaEditAny,
            self::DocumentsDownloadAny,
            self::ChangesReview,
            self::ReportsIncludeRestricted,
            self::AuditViewRecords => $editors,

            // Editors propose deletions and merges; Contributors propose additions and edits.
            self::ChangesPropose => [FamilyRole::Editor, FamilyRole::Contributor],

            self::MembersViewPrivate,
            self::MembersDelete,
            self::MediaDeleteAny,
            self::MembersInvite,
            self::RolesAssign,
            self::MembersRemove,
            self::GedcomExport,
            self::GedcomImport,
            self::AuditView,
            self::FamilyUpdate => $admins,

            self::FamilyGrantSupportAccess,
            self::FamilyTransferOwnership,
            self::FamilyDelete => $owner,
        };
    }
}
