import { Form, Head, Link, usePage } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatDateTime } from '@/lib/dates';
import { overview } from '@/routes/admin';
import { index, reinstate, suspend } from '@/routes/admin/users';

type AdminUser = {
    id: string;
    name: string;
    email: string;
    status: 'active' | 'deactivated' | 'suspended';
    is_super_admin: boolean;
    email_verified: boolean;
    two_factor_enabled: boolean;
    deletion_scheduled_at: string | null;
    last_login_at: string | null;
    created_at: string | null;
    can: { suspend: boolean; reinstate: boolean };
};

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    users: Paginated<AdminUser>;
    filters: { search: string };
};

function StatusBadge({ user }: { user: AdminUser }) {
    if (user.status === 'suspended') {
        return <Badge variant="destructive">Suspended</Badge>;
    }

    if (user.deletion_scheduled_at) {
        return <Badge variant="outline">Deleting</Badge>;
    }

    if (user.status === 'deactivated') {
        return <Badge variant="outline">Deactivated</Badge>;
    }

    return <Badge variant="secondary">Active</Badge>;
}

export default function AdminUsers({ users, filters }: Props) {
    const { auth } = usePage().props;
    const timezone = auth.user.timezone;

    return (
        <>
            <Head title="Users" />

            <div className="space-y-4">
                <Form
                    {...index.form()}
                    options={{ preserveState: true }}
                    className="flex max-w-md items-end gap-2"
                    role="search"
                >
                    <div className="grid flex-1 gap-2">
                        <Label htmlFor="search">Search by name or email</Label>
                        <Input
                            id="search"
                            type="search"
                            name="search"
                            defaultValue={filters.search}
                            placeholder="e.g. Lidau"
                        />
                    </div>
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </Form>

                <p className="text-sm text-muted-foreground" role="status">
                    {users.total.toLocaleString()}{' '}
                    {users.total === 1 ? 'account' : 'accounts'}
                    {filters.search && ` matching "${filters.search}"`}
                </p>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full text-left text-sm">
                        <thead className="border-b bg-muted/50 text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" className="px-4 py-2">
                                    User
                                </th>
                                <th scope="col" className="px-4 py-2">
                                    Status
                                </th>
                                <th scope="col" className="px-4 py-2">
                                    Security
                                </th>
                                <th scope="col" className="px-4 py-2">
                                    Last sign-in
                                </th>
                                <th scope="col" className="px-4 py-2">
                                    Joined
                                </th>
                                <th scope="col" className="px-4 py-2">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={6}
                                        className="px-4 py-8 text-center text-muted-foreground"
                                    >
                                        No accounts found.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-4 py-2">
                                        <p className="flex items-center gap-2 font-medium">
                                            {user.name}
                                            {user.is_super_admin && (
                                                <Badge>Super admin</Badge>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {user.email}
                                        </p>
                                    </td>
                                    <td className="px-4 py-2">
                                        <StatusBadge user={user} />
                                    </td>
                                    <td className="px-4 py-2 text-muted-foreground">
                                        <p>
                                            {user.email_verified
                                                ? 'Email verified'
                                                : 'Email not verified'}
                                        </p>
                                        <p>
                                            {user.two_factor_enabled
                                                ? 'Two-factor on'
                                                : 'Two-factor off'}
                                        </p>
                                    </td>
                                    <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                        {user.last_login_at
                                            ? formatDateTime(
                                                  user.last_login_at,
                                                  timezone,
                                              )
                                            : 'Never'}
                                    </td>
                                    <td className="px-4 py-2 whitespace-nowrap text-muted-foreground">
                                        {user.created_at
                                            ? formatDate(
                                                  user.created_at,
                                                  timezone,
                                              )
                                            : ''}
                                    </td>
                                    <td className="px-4 py-2 text-right">
                                        {user.status === 'suspended'
                                            ? user.can.reinstate && (
                                                  <Button
                                                      variant="outline"
                                                      size="sm"
                                                      asChild
                                                  >
                                                      <Link
                                                          href={reinstate(
                                                              user.id,
                                                          )}
                                                          as="button"
                                                          preserveScroll
                                                      >
                                                          Reinstate
                                                          <span className="sr-only">
                                                              {' '}
                                                              {user.name}
                                                          </span>
                                                      </Link>
                                                  </Button>
                                              )
                                            : user.can.suspend && (
                                                  <Button
                                                      variant="outline"
                                                      size="sm"
                                                      asChild
                                                  >
                                                      <Link
                                                          href={suspend(
                                                              user.id,
                                                          )}
                                                          as="button"
                                                          preserveScroll
                                                          onBefore={() =>
                                                              window.confirm(
                                                                  `Suspend ${user.name}? They will be signed out and unable to log in until reinstated.`,
                                                              )
                                                          }
                                                      >
                                                          Suspend
                                                          <span className="sr-only">
                                                              {' '}
                                                              {user.name}
                                                          </span>
                                                      </Link>
                                                  </Button>
                                              )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {users.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between"
                        aria-label="Pagination"
                    >
                        <p className="text-sm text-muted-foreground">
                            Page {users.current_page} of {users.last_page}
                        </p>
                        <div className="flex gap-2">
                            {users.prev_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={users.prev_page_url}>
                                        Previous
                                    </Link>
                                </Button>
                            )}
                            {users.next_page_url && (
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={users.next_page_url}>Next</Link>
                                </Button>
                            )}
                        </div>
                    </nav>
                )}
            </div>
        </>
    );
}

AdminUsers.layout = {
    breadcrumbs: [
        { title: 'Administration', href: overview() },
        { title: 'Users', href: index() },
    ],
};
