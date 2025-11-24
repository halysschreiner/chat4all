import { Component, OnInit, ViewChild, ElementRef, AfterViewChecked } from '@angular/core';
import { ChatService } from '../../services/chat.service';
import { AuthService } from '../../services/auth.service';
import { Router } from '@angular/router';

@Component({
  selector: 'app-chat',
  templateUrl: './chat.component.html',
  styleUrls: ['./chat.component.css']
})
export class ChatComponent implements OnInit, AfterViewChecked {
  conversations: any[] = [];
  selectedConversation: any = null;
  messages: any[] = [];
  newMessage = '';
  currentUser: any;
  
  showNewConversationModal = false;
  showNewGroupModal = false;
  
  newConversationUserId = '';
  newGroupName = '';
  newGroupMembers = '';

  // File upload
  selectedFile: File | null = null;
  isUploading = false;
  uploadProgress = 0;

  @ViewChild('scrollMe') private myScrollContainer!: ElementRef;

  constructor(
    private chatService: ChatService,
    private authService: AuthService,
    private router: Router
  ) {
    this.currentUser = this.authService.currentUserValue;
  }

  ngOnInit() {
    this.loadConversations();
    // Poll for new messages every 5 seconds
    setInterval(() => {
      if (this.selectedConversation) {
        this.loadMessages(this.selectedConversation.conversation_id);
      }
      this.loadConversations();
    }, 5000);
  }

  ngAfterViewChecked() {
    this.scrollToBottom();
  }

  scrollToBottom(): void {
    try {
      this.myScrollContainer.nativeElement.scrollTop = this.myScrollContainer.nativeElement.scrollHeight;
    } catch(err) { }
  }

  loadConversations() {
    this.chatService.getConversations().subscribe(response => {
      if (response.success) {
        this.conversations = response.conversations;
      }
    });
  }

  selectConversation(conversation: any) {
    this.selectedConversation = conversation;
    this.loadMessages(conversation.conversation_id);
  }

  loadMessages(conversationId: string) {
    this.chatService.getMessages(conversationId).subscribe(response => {
      if (response.success) {
        this.messages = response.messages.reverse(); // Show oldest first
        console.log('Messages loaded:', this.messages);
      }
    });
  }

  sendMessage() {
    if (!this.selectedConversation) return;
    if (!this.newMessage.trim() && !this.selectedFile) return;

    // Se tem arquivo, faz upload primeiro
    if (this.selectedFile) {
      this.uploadFile();
    } else {
      // Apenas texto
      this.chatService.sendMessage(this.selectedConversation.conversation_id, this.newMessage)
        .subscribe(response => {
          if (response.success) {
            this.newMessage = '';
            this.loadMessages(this.selectedConversation.conversation_id);
          }
        });
    }
  }

  onFileSelected(event: any) {
    const file = event.target.files[0];
    if (file) {
      // Validar tamanho (máximo 2GB)
      if (file.size > 2 * 1024 * 1024 * 1024) {
        alert('File too large. Maximum size is 2GB.');
        return;
      }
      this.selectedFile = file;
    }
  }

  removeFile() {
    this.selectedFile = null;
    this.uploadProgress = 0;
  }

  formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
  }

  async uploadFile() {
    if (!this.selectedFile || !this.selectedConversation) return;

    this.isUploading = true;
    this.uploadProgress = 0;

    try {
      // 1. Iniciar upload
      const initResponse = await this.chatService.initiateFileUpload(
        this.selectedConversation.conversation_id,
        this.selectedFile.name,
        this.selectedFile.size,
        this.selectedFile.type
      ).toPromise();

      if (!initResponse.success) {
        throw new Error('Failed to initiate upload');
      }

      const { upload_id, file_id, part_size, total_parts } = initResponse;

      // 2. Dividir arquivo em partes e fazer upload
      const partSize = part_size;
      let uploadedParts = 0;

      for (let i = 0; i < total_parts; i++) {
        const start = i * partSize;
        const end = Math.min(start + partSize, this.selectedFile.size);
        const partBlob = this.selectedFile.slice(start, end);

        // Upload da parte
        await this.chatService.uploadFilePart(
          upload_id,
          file_id,
          i + 1,
          partBlob
        ).toPromise();

        uploadedParts++;
        this.uploadProgress = Math.round((uploadedParts / total_parts) * 100);
      }

      // 3. Completar upload
      const completeResponse = await this.chatService.completeFileUpload(
        upload_id,
        file_id
      ).toPromise();

      if (!completeResponse.success) {
        throw new Error('Failed to complete upload');
      }

      // 4. Enviar mensagem com referência ao arquivo
      const caption = this.newMessage.trim() || `Sent a file: ${this.selectedFile.name}`;
      await this.chatService.sendMessage(
        this.selectedConversation.conversation_id,
        caption,
        'file',
        file_id
      ).toPromise();

      // Limpar estado
      this.newMessage = '';
      this.selectedFile = null;
      this.uploadProgress = 0;
      this.loadMessages(this.selectedConversation.conversation_id);

    } catch (error) {
      console.error('Upload error:', error);
      alert('Failed to upload file. Please try again.');
    } finally {
      this.isUploading = false;
    }
  }

  downloadFile(fileId: string) {
    console.log('Download file called with fileId:', fileId);
    
    // Usar o serviço HTTP do Angular que já inclui o token
    const url = `http://localhost:8080/v1/files/${fileId}/download`;
    
    this.chatService.downloadFile(fileId).subscribe({
      next: (blob: Blob) => {
        // Criar URL temporária para o blob
        const blobUrl = window.URL.createObjectURL(blob);
        
        // Criar link temporário e clicar nele para iniciar download
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = `file_${fileId}`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        
        // Limpar URL temporária
        window.URL.revokeObjectURL(blobUrl);
      },
      error: (error: any) => {
        console.error('Download error:', error);
        alert('Failed to download file.');
      }
    });
  }

  logout() {
    this.authService.logout();
    this.router.navigate(['/login']);
  }

  startPrivateConversation() {
    if (!this.newConversationUserId) return;
    
    this.chatService.createPrivateConversation(this.newConversationUserId)
      .subscribe({
        next: (response) => {
          if (response.success) {
            this.showNewConversationModal = false;
            this.newConversationUserId = '';
            this.loadConversations();
          } else {
            alert(response.message || 'Failed to create conversation');
          }
        },
        error: (error) => {
          console.error('Error creating conversation:', error);
          alert('An error occurred while creating the conversation.');
        }
      });
  }

  createGroup() {
    if (!this.newGroupName || !this.newGroupMembers) return;
    
    const members = this.newGroupMembers.split(',').map(id => id.trim());
    
    this.chatService.createGroupConversation(this.newGroupName, members)
      .subscribe({
        next: (response) => {
          if (response.success) {
            this.showNewGroupModal = false;
            this.newGroupName = '';
            this.newGroupMembers = '';
            this.loadConversations();
          } else {
            alert(response.message || 'Failed to create group');
          }
        },
        error: (error) => {
          console.error('Error creating group:', error);
          alert('An error occurred while creating the group.');
        }
      });
  }
}
