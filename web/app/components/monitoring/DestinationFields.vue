<script setup lang="ts">
import type { AlertDestinationOptions } from '~/types/monitoring';

/**
 * An alert destination's fields. A new one picks its type, and the fields follow it; an existing one keeps its type,
 * and its stored address, number or key is kept when left blank.
 */
const props = defineProps<{
    options: AlertDestinationOptions;
    destination?: { name: string; type: string; version: number; enabled: boolean; recipient: string | null; target: string; followsPerson: boolean; isPhone: boolean; usesUrl: boolean; isPagerDuty: boolean } | null;
}>();
const { t } = useT();
const type = ref<string | null>(props.destination?.type ?? 'email');
const chosen = computed(() => props.options.types.find((option) => option.value === type.value));
const followsPerson = computed(() => props.destination?.followsPerson ?? chosen.value?.followsPerson ?? false);
const usesUrl = computed(() => props.destination?.usesUrl ?? chosen.value?.usesUrl ?? false);
const isPhone = computed(() => props.destination?.isPhone ?? chosen.value?.isPhone ?? false);
const isPagerDuty = computed(() => props.destination?.isPagerDuty ?? type.value === 'pagerduty');
const recipient = ref<string | null>(props.destination?.recipient ?? '');
const recipients = computed(() => [...props.options.schedules, ...props.options.members]);
</script>

<template>
    <div class="grid gap-5">
        <InputField name="name" :label="t('Name')" :model-value="destination?.name" maxlength="120" required />
        <template v-if="destination">
            <input type="hidden" name="type" :value="destination.type">
            <input type="hidden" name="version" :value="destination.version">
        </template>
        <SelectField v-else v-model="type" name="type" :label="t('Type')" :options="options.types" required />
        <SelectField
            v-if="followsPerson"
            v-model="recipient"
            name="recipient_user_id"
            :label="t('Recipient')"
            :placeholder="t('Choose a member')"
            :options="recipients"
            :description="destination ? undefined : t('For email and push. A member with a verified email address, or whoever is on call in a schedule. Push reaches the devices they’ve turned on in their notification settings.')"
        />
        <InputField
            v-if="usesUrl"
            name="endpoint_url"
            :label="t('Webhook URL')"
            type="password"
            autocomplete="off"
            maxlength="2048"
            :description="destination ? t('Stored encrypted: :host. Leave blank to keep it.', { host: destination.target }) : t('For a signed webhook, Slack, Teams or Discord. A public HTTPS address on port 443.')"
        />
        <InputField
            v-if="isPhone"
            name="phone_number"
            type="tel"
            :label="t('Phone number')"
            autocomplete="off"
            maxlength="16"
            placeholder="+447700900123"
            :description="destination ? t('Stored encrypted: :number. Leave blank to keep it.', { number: destination.target }) : t('For text messages and phone calls, in international format. At most :limit a day per number.', { limit: options.dailyPhoneLimit })"
        />
        <InputField
            v-if="isPagerDuty"
            name="signing_secret"
            :label="t('PagerDuty routing key')"
            type="password"
            autocomplete="off"
            maxlength="256"
            :description="destination ? t('Leave blank to keep the stored key.') : t('For PagerDuty: the Events API v2 integration key.')"
        />
        <CheckboxField name="enabled" unchecked-value="0" :label="t('Send alerts to this destination')" :checked="destination?.enabled ?? true" />
    </div>
</template>
