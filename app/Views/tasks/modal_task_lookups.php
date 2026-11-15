<script>
    // Porting CI4 del modulo tasks: selezione delle attività completate
    // da aggiungere a una fattura (equivalente di modal_task_lookups.php CI3).
    ipReady(function () {
        var selectedTasks = [];
        document.querySelectorAll('.item-task-id').forEach(function (field) {
            if (field.value.length) {
                selectedTasks.push(parseInt(field.value, 10));
            }
        });

        var hiddenTasks = 0;
        document.querySelectorAll('.modal-task-id').forEach(function (cell) {
            var currentId = parseInt(cell.id.replace('task-id-', ''), 10);
            if (selectedTasks.indexOf(currentId) !== -1) {
                // La cella sta nella riga solo due livelli sopra.
                var row = cell.parentElement && cell.parentElement.parentElement;
                if (row) {
                    row.hidden = true;
                }
                hiddenTasks++;
            }
        });

        if (hiddenTasks >= document.querySelectorAll('.task-row').length) {
            var submit = document.getElementById('task-modal-submit');
            if (submit) {
                submit.hidden = true;
            }
        }

        // Ultimo corpo della tabella degli articoli: e' quello in cui finiscono
        // le righe aggiunte qui.
        function lastBody() {
            var bodies = document.querySelectorAll('#item_table tbody');
            return bodies.length ? bodies[bodies.length - 1] : null;
        }

        function fillLast(selector, value) {
            var body = lastBody();
            if (!body) {
                return;
            }
            body.querySelectorAll(selector).forEach(function (field) {
                field.value = value;
            });
        }

        var confirmButton = document.querySelector('.select-items-confirm');
        if (confirmButton) {
            confirmButton.addEventListener('click', function () {
                var task_ids = [];

                document.querySelectorAll("input[name='task_ids[]']:checked").forEach(function (box) {
                    task_ids.push(parseInt(box.value, 10));
                });

                ipPost("<?= site_url('tasks/ajax/process_task_selections') ?>", {
                    task_ids: task_ids
                }).then(function (data) {
                    var items = JSON.parse(data);
                    var newRow = document.getElementById('new_row');

                    Object.keys(items).forEach(function (key) {
                        var item = items[key];
                        if (!item.tax_rate_id) {
                            item.tax_rate_id = 0;
                        }

                        var body = lastBody();
                        var nameField = body ? body.querySelector('input[name=item_name]') : null;
                        if (newRow && nameField && nameField.value !== '') {
                            var row = newRow.cloneNode(true);
                            row.removeAttribute('id');
                            row.classList.add('item');
                            row.hidden = false;
                            lastBody().appendChild(row);
                        }
                        fillLast('input[name=item_task_id]', item.task_id);
                        fillLast('input[name=item_name]', item.task_name);
                        fillLast('textarea[name=item_description]', item.task_description);
                        fillLast('input[name=item_price]', item.task_price);
                        fillLast('input[name=item_quantity]', '1');
                        fillLast('select[name=item_tax_rate_id]', item.tax_rate_id);

                        var modal = document.getElementById('modal-choose-items');
                        if (modal && window.bootstrap) {
                            bootstrap.Modal.getOrCreateInstance(modal).hide();
                        }
                    });
                });
            });
        }

        // Toggle checkbox quando si clicca sulla riga
        document.querySelectorAll('#tasks_table tr').forEach(function (row) {
            row.addEventListener('click', function (event) {
                if (event.target.type === 'checkbox') {
                    return;
                }
                var box = row.querySelector('input[type=checkbox]');
                if (box) {
                    box.click();
                }
            });
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