const { createApp } = Vue;

  createApp({
    data() {
      return {
        activeTab: 'upload',
        currentFile: null,
        fileCategory: '',
        textContent: '',
        zoomLevel: 100,
        history: [],
        
        // Excel processing state
        excelData: {
          workbook: null,
          sheetNames: [],
          activeSheet: '',
          rows: []
        },

        // PPTX processing state
        pptxData: {
          slides: [],
          currentIdx: 0
        }
      };
    },
    mounted() {
      // Restore history metadata list from localStorage
      const savedHist = localStorage.getItem('doc_history');
      if (savedHist) {
        try {
          this.history = JSON.parse(savedHist);
        } catch (e) {
          this.history = [];
        }
      }
    },
    methods: {
      setTab(tab) {
        this.activeTab = tab;
      },
      closeFile() {
        if (this.currentFile && this.currentFile.url && this.currentFile.url.startsWith('blob:')) {
          URL.revokeObjectURL(this.currentFile.url);
        }
        this.currentFile = null;
        this.fileCategory = '';
        this.activeTab = 'upload';
        this.zoomLevel = 100;
        this.textContent = '';
        this.excelData = { workbook: null, sheetNames: [], activeSheet: '', rows: [] };
        this.pptxData = { slides: [], currentIdx: 0 };
      },
      dragOver(e) {
        e.currentTarget.classList.add('border-primary');
      },
      dragLeave(e) {
        e.currentTarget.classList.remove('border-primary');
      },
      dropFile(e) {
        e.currentTarget.classList.remove('border-primary');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
          this.processFile(files[0]);
        }
      },
      triggerFileInput() {
        document.getElementById('file-input').click();
      },
      fileSelected(e) {
        const files = e.target.files;
        if (files.length > 0) {
          this.processFile(files[0]);
        }
      },
      processFile(file) {
        this.closeFile(); // clean previous states

        const id = 'doc_' + Date.now();
        const url = URL.createObjectURL(file);
        
        this.currentFile = {
          id: id,
          name: file.name,
          size: file.size,
          type: file.type,
          url: url,
          rawFile: file // Keep reference to read via plugins
        };

        this.fileCategory = this.resolveFileCategory(file.name);
        
        // Add to history state
        this.addToHistory({
          id: id,
          name: file.name,
          size: file.size
        });

        // Switch to preview tab
        this.activeTab = 'viewer';

        // Load content based on file types
        this.loadFileContent(file);
      },
      resolveFileCategory(name) {
        const ext = name.split('.').pop().toLowerCase();
        if (ext === 'pdf') return 'pdf';
        if (ext === 'docx') return 'word';
        if (['xlsx', 'xls'].includes(ext)) return 'excel';
        if (ext === 'pptx') return 'powerpoint';
        if (['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'].includes(ext)) return 'image';
        if (['mp3', 'wav', 'mp4', 'webm', 'ogg'].includes(ext)) return 'media';
        if (['html', 'css', 'js', 'json', 'md', 'xml'].includes(ext)) return 'code';
        return 'text'; // standard txt or plain document
      },
      getFileCategory(name) {
        const cat = this.resolveFileCategory(name);
        return cat.toUpperCase();
      },
      getFileIcon(name) {
        const cat = this.resolveFileCategory(name);
        const icons = {
          pdf: 'fa-regular fa-file-pdf text-danger',
          word: 'fa-regular fa-file-word text-primary',
          excel: 'fa-regular fa-file-excel text-success',
          powerpoint: 'fa-regular fa-file-powerpoint text-warning',
          image: 'fa-regular fa-file-image text-info',
          media: 'fa-regular fa-file-video text-secondary',
          code: 'fa-regular fa-file-code text-dark',
          text: 'fa-regular fa-file-lines text-muted'
        };
        return icons[cat] || 'fa-regular fa-file text-muted';
      },
      formatSize(bytes) {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
      },
      zoomIn() {
        this.zoomLevel = Math.min(200, this.zoomLevel + 10);
      },
      zoomOut() {
        this.zoomLevel = Math.max(50, this.zoomLevel - 10);
      },
      
      // Load file streams into JS processors
      loadFileContent(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        const reader = new FileReader();

        if (this.fileCategory === 'text' || this.fileCategory === 'code') {
          reader.onload = (e) => {
            this.textContent = e.target.result;
          };
          reader.readAsText(file);
        }
        
        else if (this.fileCategory === 'word') {
          // Render DOCX using docx-preview
          this.$nextTick(() => {
            const container = document.getElementById('word-render-node');
            if (container) {
              container.innerHTML = '';
              docx.renderAsync(file, container)
                .catch(err => {
                  container.innerHTML = `<div class="alert alert-danger">Error rendering Word document: ${err.message}</div>`;
                });
            }
          });
        }
        
        else if (this.fileCategory === 'excel') {
          reader.onload = (e) => {
            const data = new Uint8Array(e.target.result);
            const workbook = XLSX.read(data, { type: 'array' });
            this.excelData.workbook = workbook;
            this.excelData.sheetNames = workbook.SheetNames;
            if (workbook.SheetNames.length > 0) {
              this.switchExcelSheet(workbook.SheetNames[0]);
            }
          };
          reader.readAsArrayBuffer(file);
        }

        else if (this.fileCategory === 'powerpoint') {
          // Read PPTX slide deck client-side using JSZip
          this.pptxData.slides = [];
          reader.onload = async (e) => {
            const buffer = e.target.result;
            try {
              const zip = await JSZip.loadAsync(buffer);
              const slideFiles = [];
              
              // Get slide xml structures
              zip.forEach((relativePath, fileEntry) => {
                if (relativePath.startsWith('ppt/slides/slide') && relativePath.endsWith('.xml')) {
                  slideFiles.push(fileEntry);
                }
              });

              // Sort slide numbers logically
              slideFiles.sort((a, b) => {
                const numA = parseInt(a.name.match(/\d+/)[0]);
                const numB = parseInt(b.name.match(/\d+/)[0]);
                return numA - numB;
              });

              // Parse slides XML text contents
              const parser = new DOMParser();
              const slidesArray = [];

              for (let i = 0; i < slideFiles.length; i++) {
                const xmlText = await slideFiles[i].async('text');
                const xmlDoc = parser.parseFromString(xmlText, 'application/xml');
                
                // Extract all textual tags <a:t> inside slide text body shapes
                const textNodes = xmlDoc.getElementsByTagName('a:t');
                const bullets = [];
                let slideTitle = '';

                for (let j = 0; j < textNodes.length; j++) {
                  const text = textNodes[j].textContent.trim();
                  if (text.length > 1) {
                    if (j === 0 || text.length > 30) {
                      if (!slideTitle) slideTitle = text;
                      else bullets.push(text);
                    } else {
                      bullets.push(text);
                    }
                  }
                }

                slidesArray.push({
                  title: slideTitle || `Slide ${i + 1}`,
                  bullets: bullets.length > 0 ? bullets : ['[No slide text content detected]']
                });
              }

              this.pptxData.slides = slidesArray;
            } catch (err) {
              this.pptxData.slides = [{
                title: 'Parsing Error',
                bullets: [`Could not parse presentation slides: ${err.message}`]
              }];
            }
          };
          reader.readAsArrayBuffer(file);
        }
      },

      switchExcelSheet(name) {
        this.excelData.activeSheet = name;
        const sheet = this.excelData.workbook.Sheets[name];
        // Read sheet as nested JSON arrays
        const rows = XLSX.utils.sheet_to_json(sheet, { header: 1 });
        this.excelData.rows = rows;
      },
      getExcelColHeader(colIdx) {
        // Generates spreadsheet column letters (A, B, C... Z, AA)
        let temp = colIdx;
        let letter = '';
        while (temp >= 0) {
          letter = String.fromCharCode((temp % 26) + 65) + letter;
          temp = Math.floor(temp / 26) - 1;
        }
        return letter;
      },

      // Slides Navigators
      nextSlide() {
        if (this.pptxData.currentIdx < this.pptxData.slides.length - 1) {
          this.pptxData.currentIdx++;
        }
      },
      prevSlide() {
        if (this.pptxData.currentIdx > 0) {
          this.pptxData.currentIdx--;
        }
      },

      // History handlers
      addToHistory(item) {
        // filter out existing item
        this.history = this.history.filter(h => h.name !== item.name);
        this.history.unshift(item);
        if (this.history.length > 15) {
          this.history.pop();
        }
        localStorage.setItem('doc_history', JSON.stringify(this.history));
      },
      removeFromHistory(idx) {
        this.history.splice(idx, 1);
        localStorage.setItem('doc_history', JSON.stringify(this.history));
      },
      async loadHistoryFile(hist) {
        await Swal.fire({
          title: 'File Access Notice',
          text: `Opening files from history list requires choosing the file stream again due to browser security restrictions on file paths. Please choose "${hist.name}" file from input picker.`,
          icon: 'info',
          confirmButtonColor: '#28a745'
        });
        this.triggerFileInput();
      },
      clearHistory() {
        this.history = [];
        localStorage.setItem('doc_history', JSON.stringify([]));
      },
      triggerDownload() {
        if (!this.currentFile) return;
        const link = document.createElement('a');
        link.href = this.currentFile.url;
        link.download = this.currentFile.name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
      }
    }
  }).mount('#viewer-app');
