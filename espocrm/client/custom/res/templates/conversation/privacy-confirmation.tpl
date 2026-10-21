<p>{{#if permanent}}This permanently erases the chat and its messages. It cannot be restored.{{else}}This removes the chat from the inbox and revokes visitor access. An administrator can restore its history within two calendar months.{{/if}}</p>
<label>{{#if permanent}}Type DELETE{{else}}Type 1 to confirm{{/if}}<input class="form-control" name="confirmation" autocomplete="off"></label>
{{#if permanent}}<label>Erasure reason<textarea class="form-control" name="reason" minlength="10" maxlength="500" rows="3" required></textarea></label>{{/if}}
