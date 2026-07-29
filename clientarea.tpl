<div class="row">
    <div class="col-md-10 col-md-offset-1 col-lg-8 col-lg-offset-2">

        {if $saved}
            <div class="alert alert-success">Your SMS preferences have been saved.</div>
        {/if}

        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fas fa-mobile-alt"></i> &nbsp;SMS notifications</h3>
            </div>
            <div class="panel-body">
                <p class="text-muted">Short text messages when something on your account needs attention — no spam, ever.</p>

                {if $phone}
                    <p>
                        <i class="fas fa-phone"></i> Messages go to <strong>{$phone}</strong>
                        &nbsp;<a href="clientarea.php?action=details">Update number</a>
                    </p>
                {else}
                    <div class="alert alert-warning">
                        There is no phone number on your profile yet.
                        <a href="clientarea.php?action=details">Add one</a> to receive SMS notifications.
                    </div>
                {/if}

                <form method="post" action="{$modulelink}">
                    <input type="hidden" name="beem_token" value="{$csrf}">

                    <div class="checkbox" style="margin-bottom: 15px;">
                        <label>
                            <input type="checkbox" name="sms_master"{if $master} checked{/if}>
                            <strong>Receive SMS notifications</strong>
                        </label>
                    </div>

                    <hr style="margin: 10px 0;">

                    {foreach from=$events item=ev}
                        <div class="checkbox" style="margin-bottom: 14px;">
                            <label>
                                {if $ev.client_can_optout}
                                    <input type="checkbox" name="evt[{$ev.key}]"{if $ev.enabled} checked{/if}>
                                {else}
                                    <input type="checkbox" checked disabled>
                                {/if}
                                <strong>{$ev.label}</strong>
                                {if !$ev.client_can_optout}
                                    <span class="label label-success">Always sent</span>
                                {/if}
                                <br>
                                <span class="text-muted">{$ev.description}</span>
                            </label>
                        </div>
                    {/foreach}

                    <div style="margin-top: 18px;">
                        <button type="submit" class="btn btn-primary">Save preferences</button>
                        <span class="text-muted" style="margin-left: 10px;">Standard SMS — nothing is charged to you.</span>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>
