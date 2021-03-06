{**
 * templates/management/access.tpl
 *
 * Copyright (c) 2014-2018 Simon Fraser University
 * Copyright (c) 2003-2018 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @brief The users, roles and site access settings page.
 *}
{include file="common/header.tpl" pageTitle="navigation.access"}


{assign var="uuid" value=""|uniqid|escape}
<div id="settings-access-{$uuid}">
	<tabs>
		<tab id="users" name="{translate key="manager.users"}">
			{include file="management/accessUsers.tpl"}
		</tab>
		<tab id="roles" name="{translate key="manager.roles"}">
			{help file="users-and-roles" section="roles" class="pkp_help_tab"}
			{capture assign=rolesUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.settings.roles.UserGroupGridHandler" op="fetchGrid" escape=false}{/capture}
			{load_url_in_div id="roleGridContainer" url=$rolesUrl}
		</tab>
		<tab id="access" name="{translate key="manager.siteAccessOptions.siteAccessOptions"}">
		{help file="users-and-roles" section="site-access" class="pkp_help_tab"}
			<pkp-form
				v-bind="forms.{$smarty.const.FORM_USER_ACCESS}"
				@set-fields="setFormFields"
				@set-errors="setFormErrors"
				@set-visible-locales="setFormVisibleLocales"
			/>
		</tab>
		{call_hook name="Template::Settings::access"}
	</tabs>
</div>
<script type="text/javascript">
	pkp.registry.init('settings-access-{$uuid}', 'Container', {$settingsData|json_encode});
</script>

{include file="common/footer.tpl"}
