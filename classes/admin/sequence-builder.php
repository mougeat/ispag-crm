<?php
/**
 * Template for the ISPAG Sequence Builder
 * Default language: English
 */

$interaction_types = [
    'TASK'              => '✅ ' . __( 'Task', 'ispag-crm' ),
    'EMAIL'             => '📧 ' . __( 'Email', 'ispag-crm' ),
    'CALL'              => '📞 ' . __( 'Call', 'ispag-crm' ),
    'WHATSAPP'          => '🟢 ' . __( 'WhatsApp', 'ispag-crm' ),
    'SMS'               => '📱 ' . __( 'SMS', 'ispag-crm' ),
    'LINKEDIN'          => '🔵 ' . __( 'LinkedIn', 'ispag-crm' ),
    'MEETING'           => '🤝 ' . __( 'Meeting', 'ispag-crm' ),
    'CHRISTMAS_PRESENT' => '🎁 ' . __( 'Gift', 'ispag-crm' ),
];
?>

<style>
    /* Style pour le Drag & Drop */
    #steps-list { min-height: 50px; }
    .sequence-step { cursor: default; position: relative; }
    .step-drag-handle { 
        cursor: move; 
        background: #f8f9fa; 
        padding: 5px; 
        border-right: 1px solid #ddd;
        display: flex;
        align-items: center;
        justify-content: center;
        width: 30px;
    }
    .ui-sortable-placeholder { 
        border: 2px dashed #2271b1 !important; 
        visibility: visible !important; 
        height: 100px; 
        margin-bottom: 15px;
        background: rgba(34, 113, 177, 0.05);
    }
    /* Style pour les conditions */
    .condition-logic {
        margin-top: 15px;
        padding: 10px;
        background: #fff8e5;
        border: 1px solid #ffecb5;
        border-radius: 4px;
    }
</style>


<div class="wrap ispag-crm">
    <h1 class="wp-heading-inline"><?php _e( 'Sequence Configuration', 'ispag-crm' ); ?></h1>
    <hr class="wp-header-end">

    <div id="ispag-sequence-editor" style="margin-top: 20px; max-width: 800px;">

        <input type="hidden" id="seq-id" value="<?php echo isset($sequence_to_edit) ? intval($sequence_to_edit->id) : ''; ?>">

        <div class="postbox" style="padding: 20px;">
            <div class="field-group" style="margin-bottom: 15px;">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;"><?php _e( 'Sequence Name:', 'ispag-crm' ); ?></label>
                <input type="text" id="seq-name" class="widefat" 
                    value="<?php echo isset($sequence_to_edit) ? esc_attr($sequence_to_edit->name) : ''; ?>" 
                    placeholder="<?php esc_attr_e( 'e.g. 48h Quote Follow-up', 'ispag-crm' ); ?>">
            </div>
            <div class="field-group">
                <label style="font-weight: bold; display: block; margin-bottom: 5px;"><?php _e( 'Description:', 'ispag-crm' ); ?></label>
                <textarea id="seq-desc" class="widefat" rows="4" style="height: 100px;" placeholder="<?php esc_attr_e( 'Describe the purpose of this sequence...', 'ispag-crm' ); ?>"><?php 
                    echo isset($sequence_to_edit) ? esc_textarea($sequence_to_edit->description) : ''; 
                ?></textarea>
            </div>
        </div>

        <h3><?php _e( 'Sequence Steps', 'ispag-crm' ); ?></h3>
        <div id="steps-list">
            </div>

        <div style="margin-top: 20px; padding: 15px; border: 2px dashed #ccc; text-align: center; border-radius: 8px; background: #fff;">
            <button type="button" class="button button-secondary" id="add-step-btn">
                <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span> 
                <?php _e( 'Add a Step', 'ispag-crm' ); ?>
            </button>
        </div>

        <div style="margin-top: 40px; border-top: 1px solid #ddd; padding-top: 20px; text-align: right;">
            <button type="button" class="button button-primary button-large" id="save-full-sequence">
                <?php _e( 'Save Sequence', 'ispag-crm' ); ?>
            </button>
        </div>
    </div>
</div>

<script type="text/template" id="step-template">
    <div class="sequence-step postbox" style="margin-bottom: 15px; border-left: 4px solid #2271b1; background: #fff;">
        
        <div style="display: flex;">
            <div class="step-drag-handle">
                <span class="dashicons dashicons-menu"></span>
            </div>

            <div style="flex-grow: 1; padding: 15px;">
                <div class="step-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <span class="step-badge" style="background: #2271b1; color: #fff; padding: 3px 10px; border-radius: 10px; font-size: 11px; font-weight: bold;">
                        <?php _e( 'STEP', 'ispag-crm' ); ?> <span class="step-index">1</span>
                    </span>
                    <span class="dashicons dashicons-trash remove-step" style="color: #a44; cursor: pointer;" title="<?php esc_attr_e( 'Remove', 'ispag-crm' ); ?>"></span>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <div>
                        <label><?php _e( 'Action Type:', 'ispag-crm' ); ?></label>
                        <select class="step-type widefat">
                            <?php foreach($interaction_types as $val => $label): ?>
                                <option value="<?php echo $val; ?>"><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label><?php _e( 'Condition (Optional):', 'ispag-crm' ); ?></label>
                        <select class="step-condition-type widefat">
                            <option value=""><?php _e( '-- No Condition --', 'ispag-crm' ); ?></option>
                            <optgroup label="Email">
                                <option value="MAIL_OPENED"><?php _e( 'If Mail Opened', 'ispag-crm' ); ?></option>
                            </optgroup>
                            <optgroup label="Deal / Offre">
                                <option value="DEAL_AMOUNT"><?php _e( 'Amount of the offer', 'ispag-crm' ); ?></option>
                            </optgroup>
                            <optgroup label="Contact">
                                <option value="LAST_CONTACT"><?php _e( 'Last contact > X days ago', 'ispag-crm' ); ?></option>
                            </optgroup>
                        </select>

                        <div class="condition-logic" style="display:none; margin-top:15px; background: #fff8e5; padding: 10px; border: 1px solid #ffecb5;">
                            <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 10px;">
                                
                                <select class="step-condition-operator" style="width: 70px;">
                                    <option value=">"> > </option>
                                    <option value="<"> < </option>
                                    <option value="="> = </option>
                                </select>

                                <input type="number" class="step-condition-value" placeholder="Valeur" style="width: 100px;">
                                
                                <span class="unit-label"></span>
                            </div>

                            <label style="color: #856404; font-weight: bold;">
                                <span class="dashicons dashicons-redo"></span>
                                <?php _e( 'If FALSE, skip to the next step:', 'ispag-crm' ); ?>
                            </label>
                            <input type="number" class="step-if-false" style="width: 60px;">
                        </div>
                    </div>
                    <div>
                        <label><?php _e( 'Wait Delay (days):', 'ispag-crm' ); ?></label>
                        <input type="number" class="step-delay widefat" value="2" min="0">
                    </div>
                </div>

                

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-top: 15px;">
                    <div>
                        <label><?php _e( 'Objective:', 'ispag-crm' ); ?></label>
                        <input type="text" class="step-objective widefat" placeholder="<?php esc_attr_e( 'e.g. Schedule a meeting', 'ispag-crm' ); ?>">
                    </div>
                    <div>
                        <label><?php _e( 'Value Added:', 'ispag-crm' ); ?></label>
                        <input type="text" class="step-value-added widefat" placeholder="<?php esc_attr_e( 'e.g. Send technical specs', 'ispag-crm' ); ?>">
                    </div>
                </div>

                <div style="margin-top: 15px; background: #f0f6fb; padding: 10px; border-radius: 4px; border: 1px solid #c3d7e5;">
                    <label style="font-weight: bold; color: #2271b1;">
                        <span class="dashicons dashicons-layout" style="font-size: 17px; vertical-align: middle;"></span> 
                        <?php _e( 'Use a Template (Optional):', 'ispag-crm' ); ?>
                    </label>
                    <select class="step-template-selector widefat" style="margin-top:5px;">
                        <option value=""><?php _e( '-- Custom Message --', 'ispag-crm' ); ?></option>
                        <?php foreach($all_templates as $tpl): ?>
                            <option value="<?php echo $tpl->id; ?>" data-subject="<?php echo esc_attr($tpl->subject); ?>" data-content="<?php echo esc_attr($tpl->content); ?>">
                                <?php echo esc_html($tpl->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div style="margin-top: 15px;" class="email-only-fields">
                    <label><?php _e( 'Email Subject', 'ispag-crm' ); ?>:</label>
                    <input type="text" class="step-subject widefat">
                </div>
                
                <div style="margin-top: 15px;">
                    <label style="display:block; margin-bottom:5px; font-weight: bold;"><?php _e( 'Message Content', 'ispag-crm' ); ?>:</label>
                    <textarea class="step-content-editor widefat" rows="5"></textarea>
                </div>
            </div>
        </div>
    </div>
</script>