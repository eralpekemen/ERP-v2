<div class="modal fade" id="modalProductEdit" tabindex="-1">
  <div class="modal-dialog">
    <form id="formProductEdit">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title">Ürün Düzenle</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf" value="<?= $csrf_token ?>">
          <input type="hidden" name="action" value="edit">
          <input type="hidden" name="id" id="editProductId">

          <div class="mb-3">
            <label class="form-label">Ürün Adı</label>
            <input type="text" name="name" id="editProductName" class="form-control" required>
          </div>

          <div class="mb-3">
            <label class="form-label">Kategori</label>
            <select name="category_id" id="editCategorySelect" class="form-select"></select>
          </div>

          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label">Fiyat (₺)</label>
              <input type="number" step="0.01" name="unit_price" id="editUnitPrice" class="form-control" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label">Stok</label>
              <input type="number" name="stock_quantity" id="editStockQty" class="form-control">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
          <button type="submit" class="btn btn-primary">Güncelle</button>
        </div>
      </div>
    </form>
  </div>
</div>

<script>
$(document).on('click', '.edit-product', function() {
  const id = $(this).data('id');
  $.post('admin/product_handler.php', {action: 'list', csrf: '<?= $csrf_token ?>'}, function(res) {
    const p = JSON.parse(res).find(x => x.id == id);
    $('#editProductId').val(p.id);
    $('#editProductName').val(p.name);
    $('#editUnitPrice').val(p.unit_price);
    $('#editStockQty').val(p.stock_quantity);

    // Kategorileri yükle
    $.post('admin/product_handler.php', {action: 'categories', csrf: '<?= $csrf_token ?>'}, function(cats) {
      const sel = $('#editCategorySelect').empty().append('<option value="0">-- Kategori Yok --</option>');
      cats.forEach(c => {
        const opt = `<option value="${c.id}" ${c.id == p.category_id ? 'selected' : ''}>${c.name}</option>`;
        sel.append(opt);
      });
    });
  });
});

$('#formProductEdit').on('submit', function(e) {
  e.preventDefault();
  $.post('admin/product_handler.php', $(this).serialize(), function(r) {
    const data = JSON.parse(r);
    alert(data.message);
    if (data.success) {
      $('#modalProductEdit').modal('hide');
      loadProducts();
    }
  });
});
</script>