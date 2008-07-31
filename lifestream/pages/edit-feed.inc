<h2><?php echo __('Edit Feed', 'lifestream'); ?> <small>(<a href="?page=<?php echo $basename ?>&amp;action=feeds">Back to LifeStream Feeds</a>)</small></h2>

<form action="?page=<?php echo $basename; ?>&amp;action=feeds&amp;op=edit&amp;id=<?php echo $instance->id; ?>" method="post">
    <h3><?php echo __($instance->get_constant('NAME'), 'lifestream');?> Feed Settings</h3>
    <?php if ($description = $instance->get_constant('DESCRIPTION')) { ?>
    <p><?php echo __($description, 'lifestream');?></p>
    <?php } ?>
    <input type="hidden" name="feed_type" value="<?php echo $identifier; ?>"/>
    <table class="options">
        <colgroup>
            <col style="width: 150px;"/>
            <col/>
        </colgroup>
        <tbody>
        <?php foreach ($options as $option=>$option_meta) { ?>
            <?php $current_value = (isset($_POST[$option]) ? $_POST[$option] : $instance->options[$option]); ?>
            <tr>
                <?php if (is_array($option_meta[3])) { ?>
                    <th><label<?php if ($option_meta[1]) echo ' class="required"'; ?> for="id_<?php echo $option;?>"><?php echo htmlspecialchars(__($option_meta[0], 'lifestream'));?>:</label></th>
                    <td><select name="<?php echo $option;?>">
                    <?php foreach ($option_meta[3] as $choice=>$label) { ?>
                        <option value="<?php echo $choice;?>"<?php if ($current_value == $choice) echo ' selected="selected"'; ?>><?php echo htmlspecialchars($label);?></option>
                    <?php } ?>
                    </select></td>
                <?php } elseif (is_bool($option_meta[3])) { ?>
                    <th>&nbsp;</th>
                    <td><label<?php if ($option_meta[1]) echo ' class="required"'; ?>><input type="checkbox" value="1"<?php if ($current_value == 1) echo ' checked="checked"'; ?> name="<?php echo $option;?>" /> <?php echo htmlspecialchars(__($option_meta[0], 'lifestream'));?></label></td>
                <?php } else { ?>
                    <th><label<?php if ($option_meta[1]) echo ' class="required"'; ?> for="id_<?php echo $option;?>"><?php echo htmlspecialchars(__($option_meta[0], 'lifestream'));?>:</label></th>
                    <td><input name="<?php echo $option;?>" type="text" value="<?php echo htmlspecialchars($current_value); ?>"></td>
                <?php } ?>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if ($about['url']) { ?>
    <p>Find more information about <?php echo htmlspecialchars($about['name']); ?> by visiting <a href="<?php echo htmlspecialchars($about['url']); ?>"><?php echo htmlspecialchars($about['url']); ?></a>.</p>
    <?php } ?>
    <p class="submit">
        <input type="submit" name="save" value="<?php echo __('Save Feed', 'lifestream');?>" />
    </p>

</form>