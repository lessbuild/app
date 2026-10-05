<script setup lang="ts">
import type { AcmeTone } from '~/utils/acme';
import type { MemberRow, MembersPage } from '~/types/account';

/** The people in the account: their roles and what they can reach, invitations waiting to be accepted, and leaving. */
definePageMeta({ layout: 'app', area: 'account' });
const { t, tc, dateTime } = useT();
const { data } = await useApi<MembersPage>('/account/members');
const overview = computed(() => data.value.overview);
const roleLabel = (value: string) => data.value.roles.find((role) => role.value === value)?.label ?? value;
const assignable = computed(() => data.value.roles.filter((role) => overview.value.assignableRoles.includes(role.value)).map((role) => ({ value: role.value, label: role.label })));
const serviceName = (key: string) => data.value.services.find((service) => service.key === key)?.name ?? key;
const inviteRole = ref(assignable.value.some((role) => role.value === 'member') ? 'member' : (assignable.value[0]?.value ?? ''));
const projectName = (id: string) => data.value.projects.find((project) => project.id === id)?.name ?? id;

/** What a member can reach, in one line. */
function access(member: MemberRow): string {
    const parts = [];
    if (member.serviceAccess !== null) {
        parts.push(t('Services: :services', { services: member.serviceAccess.length ? member.serviceAccess.map(serviceName).join(', ') : t('none') }));
    }
    if (member.projectAccess !== null) {
        parts.push(t('Projects: :projects', { projects: member.projectAccess.length ? member.projectAccess.map(projectName).join(', ') : t('none') }));
    }
    return parts.join(' · ');
}
</script>

<template>
    <SettingsFrame :title="t('Members')" :description="t('People who can work in :account, and what they can do.', { account: data.account.name })">
            <template v-if="overview.canManage" #actions>
                <AcmeBtn variant="primary" :to="{ query: { dialog: 'invite-member' } }" icon="plus">{{ t('Invite someone') }}</AcmeBtn>
            </template>

        <section aria-labelledby="people-heading" class="grid gap-4 border-b border-line pb-7">
            <div>
                <h2 id="people-heading" class="text-[1.125rem] font-semibold text-ink">{{ t('People') }}</h2>
                <p class="mt-1 text-[0.9375rem] text-muted">{{ tc(':count person|:count people', overview.members.length, { count: overview.members.length }) }}</p>
                <TextLink :to="{ query: { dialog: 'roles' } }" variant="muted" size="sm" class="mt-1 inline-block">{{ t('What each role can do') }}</TextLink>
            </div>
            <ul class="rounded-2xl border border-line bg-surface shadow-card min-w-0 divide-y divide-line self-start overflow-hidden">
                <li v-for="member in overview.members" :key="member.membershipId" class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <AcmeAvatar :name="member.name" size="sm" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-ink">
                            {{ member.name }}
                            <AcmeBadge v-if="member.isYou">{{ t('you') }}</AcmeBadge>
                        </p>
                        <p class="truncate text-xs text-muted">{{ member.email }}</p>
                        <p v-if="access(member)" class="mt-1 text-xs text-muted">{{ access(member) }}</p>
                    </div>
                    <AcmeBadge :tone="({ owner: 'blue', admin: 'violet' } as Record<string, AcmeTone>)[member.role] ?? 'gray'">{{ roleLabel(member.role) }}</AcmeBadge>
                    <div v-if="member.manageable" class="flex flex-wrap gap-1">
                        <FormDialog :id="`role-${member.membershipId}`" :title="t('Role for :name', { name: member.name })" :description="t('Roles apply to the whole account.')" :action="`/api/app/account/members/${member.membershipId}`" method="PUT" :submit="t('Save')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Role') }}</AcmeBtn></template>
                            <fieldset class="grid gap-2">
                                <legend class="sr-only">{{ t('Role') }}</legend>
                                <ChoiceField
                                    v-for="role in data.roles.filter((item) => overview.assignableRoles.includes(item.value))"
                                    :id="`role-${member.membershipId}-${role.value}`"
                                    :key="role.value"
                                    name="role"
                                    type="radio"
                                    card
                                    :value="role.value"
                                    :label="role.label"
                                    :description="role.description"
                                    :checked="member.role === role.value"
                                />
                            </fieldset>
                        </FormDialog>
                        <FormDialog :id="`projects-${member.membershipId}`" :title="t('Project access')" :description="t('Which projects :name can see', { name: member.name })" :action="`/api/app/account/members/${member.membershipId}/projects`" method="PUT" :submit="t('Save project access')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Access') }}</AcmeBtn></template>
                            <ChoiceField :id="`projects-${member.membershipId}-all`" name="project_access" type="radio" value="all" :label="t('Every project, including new ones')" :checked="member.projectAccess === null" />
                            <ChoiceField :id="`projects-${member.membershipId}-some`" name="project_access" type="radio" value="some" :label="t('Only these projects:')" :checked="member.projectAccess !== null">
                                <span class="mt-2 grid gap-1">
                                    <label v-for="project in data.projects" :key="project.id" class="inline-flex items-center gap-2 text-sm text-ink">
                                        <input type="checkbox" class="ui-check" name="projects[]" :value="project.id" :checked="member.projectAccess?.includes(project.id)">
                                        {{ project.name }}
                                    </label>
                                </span>
                            </ChoiceField>
                            <CheckboxField :id="`projects-${member.membershipId}-protected`" name="deploy_protected" unchecked-value="0" :label="t('Can deploy to and change protected environments')" :checked="member.deployProtected" />
                            <template v-if="member.canLimitServices">
                                <hr class="border-line">
                                <p class="text-sm font-bold text-ink">{{ t('Service access') }}</p>
                                <p class="text-sm text-muted">{{ t('Which services :name can use', { name: member.name }) }}</p>
                                <AcmeBtn size="sm" :to="{ query: { dialog: `services-${member.membershipId}` } }">{{ t('Service access') }}</AcmeBtn>
                            </template>
                        </FormDialog>
                        <FormDialog v-if="member.canLimitServices" :id="`services-${member.membershipId}`" :title="t('Service access')" :description="t('Which services :name can use', { name: member.name })" :action="`/api/app/account/members/${member.membershipId}/services`" method="PUT" :submit="t('Save service access')">
                            <ChoiceField :id="`services-${member.membershipId}-all`" name="access" type="radio" value="all" :label="t('Every service, including new ones')" :checked="member.serviceAccess === null" />
                            <ChoiceField :id="`services-${member.membershipId}-some`" name="access" type="radio" value="some" :label="t('Only these services:')" :checked="member.serviceAccess !== null">
                                <span class="mt-2 grid gap-1">
                                    <label v-for="service in data.services" :key="service.key" class="inline-flex items-center gap-2 text-sm text-ink">
                                        <input type="checkbox" class="ui-check" name="services[]" :value="service.key" :checked="member.serviceAccess?.includes(service.key)">
                                        {{ service.name }}
                                    </label>
                                </span>
                            </ChoiceField>
                        </FormDialog>
                        <DeleteDialog :id="`remove-${member.membershipId}`" :title="t('Remove :name?', { name: member.name })" :action="`/api/app/account/members/${member.membershipId}`" :warning="t('They lose access to :account straight away.', { account: data.account.name })" :submit-label="t('Remove')">
                            <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Remove') }}</AcmeBtn></template>
                        </DeleteDialog>
                    </div>
                    <DeleteDialog v-else-if="member.isYou" :id="`leave-${member.membershipId}`" :title="t('Leave :account?', { account: data.account.name })" :action="`/api/app/account/members/${member.membershipId}`" :warning="member.role === 'owner' ? t('An account always keeps an owner, so the last owner can’t leave.') : t('You lose access until someone invites you again.')" :submit-label="t('Leave')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Leave') }}</AcmeBtn></template>
                    </DeleteDialog>
                </li>
            </ul>
        </section>

        <section v-if="overview.canManage" aria-labelledby="invitations-heading" class="grid gap-4 border-b border-line pb-7 last:border-b-0 md:grid-cols-[minmax(0,1fr)_minmax(0,1.3fr)] md:gap-10">
            <div>
                <h2 id="invitations-heading" class="text-[1.125rem] font-semibold text-ink">{{ t('Pending invitations') }}</h2>
                <p class="mt-1 text-[0.9375rem] text-muted">{{ t('Invitations that haven’t been accepted yet.') }}</p>
            </div>
            <p v-if="overview.invitations.length === 0" class="self-start rounded-lg border border-dashed border-line px-4 py-3 text-sm text-muted">{{ t('No pending invitations.') }}</p>
            <ul v-else class="min-w-0 space-y-2 self-start">
                <li v-for="invitation in overview.invitations" :key="invitation.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed border-line px-3 py-2">
                    <div class="flex min-w-0 items-center gap-3">
                        <AcmeIcon name="mail" :size="16" class="shrink-0 text-muted" />
                        <div class="min-w-0">
                        <p class="text-sm font-medium text-ink">{{ invitation.email }}</p>
                        <p class="text-xs text-muted">
                            {{ roleLabel(invitation.role) }}<template v-if="invitation.invitedBy"> · {{ t('Invited by :name', { name: invitation.invitedBy }) }}</template> · {{ t('Expires :time', { time: dateTime(invitation.expiresAt) }) }}
                        </p>
                        </div>
                    </div>
                    <DeleteDialog :id="`revoke-${invitation.id}`" :title="t('Revoke the invitation for :email', { email: invitation.email })" :action="`/api/app/account/invitations/${invitation.id}`" :warning="t('You can invite them again later.')" :submit-label="t('Revoke')">
                        <template #trigger="{ open }"><AcmeBtn variant="ghost" size="sm" @click="open">{{ t('Revoke') }}</AcmeBtn></template>
                    </DeleteDialog>
                </li>
            </ul>
        </section>

        <FormDialog v-if="overview.canManage" id="invite-member" :title="t('Invite someone')" :description="t('They get an email with a link that works for :days days. Inviting the same address again replaces the earlier invitation.', { days: data.invitationDays })" action="/api/app/account/invitations" :submit="t('Send invitation')">
            <InputField name="email" :label="t('Email address')" type="email" autocomplete="off" required autofocus />
            <SelectField v-model="inviteRole" name="role" :label="t('Role')" :options="assignable" required />
        </FormDialog>

        <UiDialog id="roles" :title="t('What each role can do')" :description="t('Roles apply to the whole account.')">
            <dl class="grid gap-4">
                <div v-for="role in data.roles" :key="role.value">
                    <dt class="font-bold text-ink">{{ role.label }}</dt>
                    <dd class="mt-1 text-sm text-muted">{{ role.description }}</dd>
                </div>
            </dl>
        </UiDialog>
    </SettingsFrame>
</template>
