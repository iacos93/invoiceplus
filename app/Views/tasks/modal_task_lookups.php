<script>
    // Porting CI4 del modulo tasks: selezione delle attività completate
    // da aggiungere a una fattura (equivalente di modal_task_lookups.php CI3).
    // Richiede jQuery + Bootstrap, caricati dal modulo invoices (CI4).
    $(function () {
        var selectedTasks = [];
        $('.item-task-id').each(function () {
            var currentVal = $(this).val();
            if (currentVal.length) {
                selectedTasks.push(parseInt(currentVal));
            }
        });

        var hiddenTasks = 0;
        $('.modal-task-id').each(function () {
            var currentId = parseInt($(this).attr('id').replace('task-id-', ''));
            if (selectedTasks.indexOf(currentId) !== -1) {
                $('#task-id-' + currentId).parent().parent().hide();
                hiddenTasks++;
            }
        });

        if (hiddenTasks >= $('.task-row').length) {
            $('#task-modal-submit').hide();
        }

        $('.select-items-confirm').click(function () {
            var task_ids = [];

            $("input[name='task_ids[]']:checked").each(function () {
                task_ids.push(parseInt($(this).val()));
            });

            $.post("<?= site_url('tasks/ajax/process_task_selections') ?>", {
                task_ids: task_ids
            }, function (data) {
                var items = JSON.parse(data);

                for (var key in items) {
                    if (!items[key].tax_rate_id) items[key].tax_rate_id = 0;

                    if ($('#item_table tbody:last input[name=item_name]').val() !== '') {
                        $('#new_row').clone().appendTo('#item_table').removeAttr('id').addClass('item').show();
                    }
                    $('#item_table tbody:last input[name=item_task_id]').val(items[key].task_id);
                    $('#item_table tbody:last input[name=item_name]').val(items[key].task_name);
                    $('#item_table tbody:last textarea[name=item_description]').val(items[key].task_description);
                    $('#item_table tbody:last input[name=item_price]').val(items[key].task_price);
                    $('#item_table tbody:last input[name=item_quantity]').val('1');
                    $('#item_table tbody:last select[name=item_tax_rate_id]').val(items[key].tax_rate_id);

                    $('#modal-choose-items').modal('hide');
                }
            });
        });

        // Toggle checkbox quando si clicca sulla riga
        $('#tasks_table tr').click(function (event) {
            if (event.target.type !== 'checkbox') {
                $(':checkbox', this).trigger('click');
            }
        });
    });
</script>

<div class="table-responsive">
    <table id="tasks_table" class="table table-hover table-bordered no-margin">
        <tr>
            <?php /* La colonna delle caselle non ha un titolo visibile: senza un
                     testo (anche solo per lo screen reader) la colonna resta
                     senza nome nella navigazione per tabelle. */ ?>
            <th scope="col"><span class="visually-hidden"><?= trans('select', '', 'Seleziona') ?></span></th>
            <th scope="col"><?= trans('project_name') ?></th>
            <th scope="col"><?= trans('task_name') ?></th>
            <th scope="col"><?= trans('task_finish_date') ?></th>
            <th scope="col"><?= trans('task_description') ?></th>
            <th scope="col" class="text-end"><?= trans('task_price') ?></th>
        </tr>

        <?php foreach ($tasks as $task): ?>
            <tr class="task-row">
                <td>
                    <input type="checkbox" class="modal-task-id" name="task_ids[]"
                           id="task-id-<?= (int) $task['task_id'] ?>" value="<?= (int) $task['task_id'] ?>">
                </td>
                <td><b><?= isset($task['project_name']) ? esc($task['project_name']) : '' ?></b></td>
                <td><b><?= esc($task['task_name']) ?></b></td>
                <td><b><?= date_from_mysql($task['task_finish_date'] ?? '') ?></b></td>
                <td><?= nl2br(esc($task['task_description'] ?? '')) ?></td>
                <td class="amount"><?= format_currency($task['task_price']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
</div>