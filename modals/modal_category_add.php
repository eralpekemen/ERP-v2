<!-- modals/modal_category_add.php -->
<div class="modal fade" id="modalCategoryAdd" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form id="formCategoryAdd">
                <div class="modal-header">
                    <h5 class="modal-title">Yeni Kategori Ekle</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Kategori Adı *</label>
                        <input type="text" class="form-control" name="name" placeholder="Örn: İçecekler, Tatlılar" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">İptal</button>
                    <button type="submit" class="btn btn-success">Kategori Ekle</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.getElementById('formCategoryAdd')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const form = e.target;
        const btn = form.querySelector('button[type="submit"]');
        const modal = form.closest('.modal');
        
        btn.disabled = true;
        btn.innerHTML = 'Ekleniyor...';

        const formData = new FormData(form);
        formData.append('action', 'add_category');
        formData.append('csrf', CSRF);

        try {
            const res = await fetch('admin/product_handler.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                alert('Kategori eklendi: ' + data.name);
                bootstrap.Modal.getInstance(modal).hide();
                form.reset();

                // Ürün ekleme modalı açıksa kategorileri yenile
                if (document.getElementById('modalProductAdd')?.classList.contains('show')) {
                    loadCategoriesForProduct();
                }
            } else {
                alert('Hata: ' + (data.message || 'Bilinmeyen hata'));
            }
        } catch (err) {
            console.error('Kategori ekleme hatası:', err);
            alert('Sunucu hatası!');
        } finally {
            btn.disabled = false;
            btn.innerHTML = 'Kategori Ekle';
        }
    });
</script>