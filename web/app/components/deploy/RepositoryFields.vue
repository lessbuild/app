<script setup lang="ts">
import type { RepositoryDetail, RepositoryFormOptions } from '~/types/deploy';

/**
 * A repository's fields, for connecting one and for its settings: its Git provider and address, the branch, the
 * website and environment it deploys to, and the commands around a deploy. `defaults` fills a new one (from the
 * GitHub App's list of repositories).
 */
const props = withDefaults(defineProps<{ options: RepositoryFormOptions; repository?: RepositoryDetail | null; defaults?: { providerId?: string; url?: string; branch?: string } }>(), {
    repository: null,
    defaults: () => ({}),
});
const { t } = useT();
const provider = ref<string | null>(props.repository?.providerId != null ? String(props.repository.providerId) : (props.defaults.providerId ?? props.options.providers[0]?.value ?? null));
const website = ref<string | null>(props.repository ? String(props.repository.websiteId) : (props.options.websites[0]?.value ?? null));
const environment = ref<string | null>(props.repository?.environmentId ?? '');
</script>

<template>
    <div class="grid items-start gap-5 sm:grid-cols-2">
        <InputField name="name" :label="t('Name')" :model-value="repository?.name" maxlength="120" required />
        <SelectField
            v-model="provider"
            name="provider_id"
            :label="t('Git provider')"
            :options="options.providers"
            :description="options.providers.length === 0 ? t('Add a GitHub, GitLab or Bitbucket token under Account → Providers first.') : undefined"
        />
        <InputField name="url" :label="t('Repository')" :model-value="repository?.url ?? defaults.url" placeholder="github.com/acme/shop" maxlength="255" required />
        <InputField name="branch" :label="t('Branch')" :model-value="repository?.branch ?? defaults.branch ?? 'main'" maxlength="255" required />
        <SelectField v-model="website" name="website_id" :label="t('Deploys to website')" :options="options.websites" />
        <SelectField v-model="environment" name="environment_id" :label="t('Environment')" :description="t('Deploys show up in its Monitoring releases.')" :placeholder="t('None')" :options="options.environments" />
        <InputField name="deployment_root" :label="t('Subdirectory (optional)')" :model-value="repository?.deploymentRoot" placeholder="apps/api" maxlength="512" />
        <div class="grid gap-5 sm:col-span-2 sm:grid-cols-2">
            <TextareaField name="build_commands" :label="t('Build commands')" rows="3" :model-value="repository?.buildCommands" :description="t('Run in the new release before it goes live.')" />
            <TextareaField name="post_deployment_commands" :label="t('After-deploy commands')" rows="3" :model-value="repository?.postDeploymentCommands" :description="t('Run once the release is live.')" />
            <TextareaField name="auto_deploy_include_paths" :label="t('Push deploys only for these paths')" rows="2" :model-value="repository?.autoDeployIncludePaths.join('\n')" :description="t('One glob per line, e.g. app/**; leave empty for any change.')" />
            <TextareaField name="auto_deploy_exclude_paths" :label="t('Ignore pushes that only change')" rows="2" :model-value="repository?.autoDeployExcludePaths.join('\n')" :description="t('One glob per line, e.g. docs/** or *.md.')" />
        </div>
    </div>
</template>
