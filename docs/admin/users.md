# MurCMS admin: users and access levels

Date: 2026-09-07
Status: current MurCMS 0.4.1 behavior

Users are stored in:

```text
root/data/config/admin.php
```

The public `admin.dist.php` intentionally contains no users or shared password.
For a new checkout, `scripts/create-admin-config.ps1` creates the first local
`super_admin` record and refuses to overwrite an existing `admin.php`.

Management routes:

```text
/admin/settings/users
/admin/settings/users/edit
/admin/settings/users/delete
/admin/settings/profile
```

## Roles

`super_admin` is the single owner-level account. It has every admin permission
and can create, edit, deactivate, change passwords for, and delete `admin` and
`editor` accounts. The UI cannot create, demote, deactivate, or delete a
super-admin.

`admin` has all normal CMS and configuration permissions. It can manage only
`editor` accounts. It cannot change another admin or the super-admin.

`editor` manages content:

- pages and categories, including bulk operations;
- page tags and the tag overview;
- home/footer blocks, galleries, carousel/counters/logos libraries;
- Media Manager files and folders;
- its own profile, interface language, and password.

An editor cannot access trusted HTML snippets, SEO configuration, plugin
activation/settings, site/language/admin settings, template configuration, or
user management.

The old `content` role remains a runtime alias of `editor` for upgrades, but
new and distributed configs use `editor`.

## Profiles and passwords

Every user record contains:

```text
id
username
display_name
email
phone
role
admin_language
active
password_hash
```

Users can update their own display name, e-mail, phone, interface language, and
password. Usernames are immutable after creation. E-mail and username values
are unique case-insensitively.

Managed password changes do not require the target user's old password. A
password must contain 14–200 characters, lower- and uppercase letters, a
number, and a special character.

Deleting or deactivating a user invalidates that user's session on its next
request. Changing a password changes the credential fingerprint and also
invalidates older sessions. Forgot-password recovery is intentionally not part
of this implementation.

All writes require CSRF validation and the appropriate role. Before replacing
`admin.php`, MurCMS keeps two rotating copies under:

```text
root/data/storage/backups/config/admin.php.bak.1
root/data/storage/backups/config/admin.php.bak.2
```

The distribution starts with one `admin` username whose role is
`super_admin`. No additional admin or editor demo account is created.
