document.addEventListener("DOMContentLoaded", () => {
  const paper = document.getElementById("ticketPaper");
  const preview = document.querySelector(".ticket-config-preview");
  const frame = document.getElementById("ticketPreviewFrame");
  const openLink = document.getElementById("ticketPreviewOpen");
  const printButton = document.getElementById("ticketPreviewPrint");
  const saleId = Number(preview?.dataset.saleId || 0);

  function ticketUrl() {
    const params = new URLSearchParams({
      id: String(saleId),
      paper: paper?.value === "58" ? "58" : "80",
    });
    return `ticket.php?${params.toString()}`;
  }

  function refreshPreview() {
    if (!saleId || !frame) return;
    const url = ticketUrl();
    frame.src = url;
    if (openLink) openLink.href = url;
  }

  paper?.addEventListener("change", refreshPreview);
  printButton?.addEventListener("click", () => {
    const target = frame?.contentWindow;
    if (!target) return;
    target.focus();
    target.print();
  });
});
